package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"math"
	"net"
	"net/http"
	"os"
	"os/exec"
	"regexp"
	"runtime"
	"runtime/debug"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
	_ "time/tzdata"

	"github.com/valyala/fasthttp"
)

// ======================================================================
// GOPAY MLBB WDP - WAR EDITION (GOLANG + FASTHTTP)
// Target: 4 user berbeda | Single salvo tanpa retry | 4 dedicated TLS lanes
// ======================================================================

const (
	MAX_USERS                  = 4
	CAPTCHA_LEAD               = 10 * time.Second
	CAPTCHA_TIMEOUT            = 5 * time.Second
	PRECONNECT_LEAD            = 5 * time.Second
	PRECONNECT_TIMEOUT         = 1500 * time.Millisecond
	PREBUILD_LEAD              = 2 * time.Second
	ARM_LEAD                   = 500 * time.Millisecond
	MAX_RELEASE_LATE           = 20 * time.Millisecond
	INQUIRY_TIMEOUT_MS         = 5200
	PAYMENT_TIMEOUT_MS         = 5200
	TRANSACTION_POLL_TIMEOUT   = 15 * time.Second
	CLOCK_WAIT_MAX             = 30 * time.Second
	CLOCK_OFFSET_LIMIT_DEFAULT = 5.0
	CLOCK_RMS_LIMIT_DEFAULT    = 10.0
	CLOCK_BOUND_LIMIT_DEFAULT  = 50.0
	CLOCK_SKEW_LIMIT_PPM       = 100.0
	MIN_FIXED_LEAD_MS          = -499
	MAX_FIXED_LEAD_MS          = 499
	PROGRAM_VERSION            = "2026.07.22-timing-v3"
)

// Pola error (sama persis dengan PHP)
var (
	STOP_PATTERNS = []string{
		"out of stock", "sold out", "kuota habis", "voucher habis",
		"stok habis", "sudah habis",
	}
	SKIP_USER_PATTERNS = []string{
		"reached the redeem limit", "already redeemed", "sudah pernah",
		"act_subscrip_no_config", "subscrip_no_config",
	}
	INVALID_USER_PATTERNS = []string{
		"role_null", "role null", "Error_Role_Null", "error_role_null",
		"invalid user", "user not found", "user_not_found",
		"Error_InvalidZoneId", "invalid zone",
	}
	REGION_BLOCK_PATTERNS = []string{
		"regional restrictions", "region restriction", "outside region",
		"outside regional", "di luar region", "diluar region", "luar region",
		"luar zona promo", "zona promo",
	}
	RETRY_PATTERNS = []string{
		"not available", "not yet", "belum dimulai", "belum tersedia",
		"tidak tersedia", "try again", "temporarily", "service unavailable",
	}
)

// Global state (thread-safe)
var (
	mu         sync.Mutex
	successMap = make(map[string]SuccessEntry)

	attemptStats []AttemptStat
	warStartWall time.Time
)

type Order struct {
	UserID   string
	ServerID string
}

type SuccessEntry struct {
	Order   Order
	OrderID string
	Lane    *inquiryLane
}

type InquiryResult struct {
	Status     string
	OrderID    string
	ErrMsg     string
	HTTPCode   int
	RTTMs      float64
	CallAt     time.Time
	DoneAt     time.Time
	Transport  TransportSnapshot
	CFRay      string
	Via        string
	UpstreamMS string
	RetryAfter string
	RateRemain string
	RateLimit  string
}

type AttemptStat struct {
	User            string
	RTTMs           float64
	FireOffset      float64
	WriteOffset     float64
	FirstByteOffset float64
	TTFBMs          float64
	HTTPCode        int
	Verdict         string
	Reused          bool
	ColdDial        bool
	LateBlocked     bool
	BytesWritten    int64
}

// ===================== DEDICATED FASTHTTP LANES =====================

const (
	lanePhaseIdle int32 = iota
	lanePhaseFinal
)

var (
	telemetryEpoch = time.Now()
	sharedDialer   = &fasthttp.TCPDialer{
		Concurrency:      64,
		DNSCacheDuration: 10 * time.Minute,
	}
	sharedTLSCache = tls.NewLRUClientSessionCache(32)
	inquiryLanes   []*inquiryLane
	sessionUA      string
	sessionSecCH   string

	errFinalWriteTooLate = errors.New("final inquiry diblokir: first write melewati batas keterlambatan")
)

type sessionCookieJar struct {
	mu     sync.RWMutex
	values map[string]string
}

func newSessionCookieJar() *sessionCookieJar {
	return &sessionCookieJar{values: map[string]string{
		"slug": "mobile-legends-bang-bang",
	}}
}

func (j *sessionCookieJar) apply(h *fasthttp.RequestHeader) {
	j.mu.RLock()
	defer j.mu.RUnlock()
	for key, value := range j.values {
		if value != "" {
			h.SetCookie(key, value)
		}
	}
}

func (j *sessionCookieJar) capture(h *fasthttp.ResponseHeader) {
	j.mu.Lock()
	defer j.mu.Unlock()
	h.VisitAllCookie(func(_, raw []byte) {
		cookie := fasthttp.AcquireCookie()
		defer fasthttp.ReleaseCookie(cookie)
		if err := cookie.ParseBytes(raw); err != nil {
			return
		}
		key := string(cookie.Key())
		value := string(cookie.Value())
		if key == "" {
			return
		}
		expire := cookie.Expire()
		if cookie.MaxAge() < 0 ||
			(!expire.Equal(fasthttp.CookieExpireUnlimited) && expire.Before(time.Now())) {
			delete(j.values, key)
			return
		}
		if value == "" {
			delete(j.values, key)
			return
		}
		j.values[key] = value
	})
}

type inquiryLane struct {
	id        int
	client    *fasthttp.HostClient
	tlsConfig *tls.Config
	cookies   *sessionCookieJar

	phase atomic.Int32
	seq   atomic.Int64

	finalWriteNS    atomic.Int64
	finalWriteEndNS atomic.Int64
	finalFirstNS    atomic.Int64
	finalConnID     atomic.Int64
	finalBytes      atomic.Int64
	coldDial        atomic.Bool
	lateBlocked     atomic.Bool
	writeAckOnce    sync.Once
	writeAck        *sync.WaitGroup
	warmConnID      int64
	finalCutoff     time.Time

	metaMu      sync.Mutex
	remoteAddr  string
	dialStartNS int64
	dialEndNS   int64
}

type observedTLSConn struct {
	*tls.Conn
	lane   *inquiryLane
	connID int64
}

type TransportSnapshot struct {
	WriteAt      time.Time
	WriteEndAt   time.Time
	FirstByteAt  time.Time
	RemoteAddr   string
	ConnID       int64
	WarmConnID   int64
	Reused       bool
	ColdDial     bool
	LateBlocked  bool
	BytesWritten int64
	DialMs       float64
}

func telemetryNowNS() int64 {
	return time.Since(telemetryEpoch).Nanoseconds()
}

func telemetryTime(ns int64) time.Time {
	if ns <= 0 {
		return time.Time{}
	}
	return telemetryEpoch.Add(time.Duration(ns))
}

func (c *observedTLSConn) Write(p []byte) (int, error) {
	isFinal := c.lane.phase.Load() == lanePhaseFinal
	if isFinal {
		if err := c.lane.beginFinalWrite(c.connID, time.Now()); err != nil {
			return 0, err
		}
	}

	n, err := c.Conn.Write(p)
	if isFinal {
		if n > 0 {
			c.lane.finalBytes.Add(int64(n))
		}
		c.lane.finalWriteEndNS.Store(telemetryNowNS())
	}
	return n, err
}

func (c *observedTLSConn) Read(p []byte) (int, error) {
	n, err := c.Conn.Read(p)
	if n > 0 && c.lane.phase.Load() == lanePhaseFinal {
		c.lane.finalFirstNS.CompareAndSwap(0, telemetryNowNS())
	}
	return n, err
}

func (l *inquiryLane) dialTLS(addr string, timeout time.Duration) (net.Conn, error) {
	if timeout <= 0 {
		timeout = time.Duration(INQUIRY_TIMEOUT_MS) * time.Millisecond
	}
	isFinal := l.phase.Load() == lanePhaseFinal
	if isFinal {
		l.coldDial.Store(true)
		l.metaMu.Lock()
		l.dialStartNS = telemetryNowNS()
		l.dialEndNS = 0
		l.remoteAddr = ""
		l.metaMu.Unlock()

		remaining := time.Until(l.finalCutoff)
		if remaining <= 0 {
			l.lateBlocked.Store(true)
			l.markDialDone("")
			return nil, errFinalWriteTooLate
		}
		if timeout > remaining {
			timeout = remaining
		}
	}
	deadline := time.Now().Add(timeout)

	raw, err := sharedDialer.DialTimeout(addr, timeout)
	if err != nil {
		l.markDialDone("")
		return nil, err
	}

	if time.Until(deadline) <= 0 {
		raw.Close()
		l.markDialDone("")
		return nil, fmt.Errorf("TCP dial menghabiskan seluruh timeout %s", timeout)
	}
	if err := raw.SetDeadline(deadline); err != nil {
		raw.Close()
		l.markDialDone("")
		return nil, err
	}

	tc := tls.Client(raw, l.tlsConfig.Clone())
	if err := tc.Handshake(); err != nil {
		raw.Close()
		l.markDialDone("")
		return nil, err
	}
	if isFinal && l.finalWriteIsLate(time.Now()) {
		l.lateBlocked.Store(true)
		tc.Close()
		l.markDialDone("")
		return nil, errFinalWriteTooLate
	}
	if err := tc.SetDeadline(time.Time{}); err != nil {
		tc.Close()
		l.markDialDone("")
		return nil, err
	}

	connID := l.seq.Add(1)
	l.markDialDone(tc.RemoteAddr().String())
	return &observedTLSConn{Conn: tc, lane: l, connID: connID}, nil
}

func (l *inquiryLane) markDialDone(remote string) {
	l.metaMu.Lock()
	defer l.metaMu.Unlock()
	l.dialEndNS = telemetryNowNS()
	if remote != "" {
		l.remoteAddr = remote
	}
}

func (l *inquiryLane) armFinal(writeAck *sync.WaitGroup, cutoff time.Time) {
	l.writeAck = writeAck
	l.finalCutoff = cutoff
	l.phase.Store(lanePhaseFinal)
}

func (l *inquiryLane) finishFinal() {
	l.phase.Store(lanePhaseIdle)
	l.ackFirstWrite()
}

func (l *inquiryLane) ackFirstWrite() {
	l.writeAckOnce.Do(func() {
		if l.writeAck != nil {
			l.writeAck.Done()
		}
	})
}

func (l *inquiryLane) finalWriteIsLate(now time.Time) bool {
	return !l.finalCutoff.IsZero() && now.After(l.finalCutoff)
}

func (l *inquiryLane) beginFinalWrite(connID int64, now time.Time) error {
	if l.lateBlocked.Load() {
		return errFinalWriteTooLate
	}
	if !l.finalWriteNS.CompareAndSwap(0, telemetryNowNS()) {
		return nil
	}
	l.finalConnID.Store(connID)
	// Ack saat fasthttp sudah memasuki plaintext TLS write. Jangan
	// menahan lane lain bila kernel write pada lane ini tersendat.
	l.ackFirstWrite()
	if l.finalWriteIsLate(now) {
		l.lateBlocked.Store(true)
		return errFinalWriteTooLate
	}
	return nil
}

func (l *inquiryLane) snapshot() TransportSnapshot {
	l.metaMu.Lock()
	remote := l.remoteAddr
	dialStart := l.dialStartNS
	dialEnd := l.dialEndNS
	l.metaMu.Unlock()

	connID := l.finalConnID.Load()
	s := TransportSnapshot{
		WriteAt:      telemetryTime(l.finalWriteNS.Load()),
		WriteEndAt:   telemetryTime(l.finalWriteEndNS.Load()),
		FirstByteAt:  telemetryTime(l.finalFirstNS.Load()),
		RemoteAddr:   remote,
		ConnID:       connID,
		WarmConnID:   l.warmConnID,
		ColdDial:     l.coldDial.Load(),
		LateBlocked:  l.lateBlocked.Load(),
		BytesWritten: l.finalBytes.Load(),
	}
	s.Reused = connID != 0 && connID == l.warmConnID && !s.ColdDial
	if dialStart > 0 && dialEnd >= dialStart {
		s.DialMs = float64(dialEnd-dialStart) / float64(time.Millisecond)
	}
	return s
}

func newInquiryLane(id int) *inquiryLane {
	lane := &inquiryLane{
		id:      id,
		cookies: newSessionCookieJar(),
		tlsConfig: &tls.Config{
			ServerName:         "gopay.co.id",
			MinVersion:         tls.VersionTLS12,
			NextProtos:         []string{"http/1.1"},
			ClientSessionCache: sharedTLSCache,
		},
	}
	lane.client = &fasthttp.HostClient{
		Addr:                          "gopay.co.id:443",
		IsTLS:                         true,
		MaxConns:                      1,
		MaxIdemponentCallAttempts:     1,
		MaxIdleConnDuration:           30 * time.Second,
		ReadTimeout:                   time.Duration(INQUIRY_TIMEOUT_MS) * time.Millisecond,
		WriteTimeout:                  time.Duration(INQUIRY_TIMEOUT_MS) * time.Millisecond,
		MaxConnWaitTimeout:            0,
		DisableHeaderNamesNormalizing: true,
		DisablePathNormalizing:        true,
		DialTimeout:                   lane.dialTLS,
	}
	return lane
}

func initFastHTTPClients() {
	sessionUA, sessionSecCH = getRandomUserAgent()
	inquiryLanes = make([]*inquiryLane, MAX_USERS)
	for i := range inquiryLanes {
		inquiryLanes[i] = newInquiryLane(i + 1)
	}
}

// ===================== UTIL =====================

func getRandomUserAgent() (string, string) {
	androidVersions := []string{"12", "13", "14", "15"}
	models := []string{
		"SM-A536B", "SM-A546B", "SM-A356E", "Redmi Note 13", "Poco X6",
		"RMX3780", "SM-A256E", "SM-A346B",
	}
	chromeVersions := []string{"135", "136", "137", "138", "139"}

	av := androidVersions[randomInt(len(androidVersions))]
	model := models[randomInt(len(models))]
	cv := chromeVersions[randomInt(len(chromeVersions))]

	ua := fmt.Sprintf("Mozilla/5.0 (Linux; Android %s; %s Build/TP1A.220624.014) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/%s.0.0.0 Mobile Safari/537.36", av, model, cv)
	secCh := fmt.Sprintf(`"Android WebView";v="%s", "Chromium";v="%s", "Not/A)Brand";v="24"`, cv, cv)
	return ua, secCh
}

func randomInt(max int) int {
	b := make([]byte, 1)
	rand.Read(b)
	return int(b[0]) % max
}

func generateSentryTrace() (string, string) {
	traceID := make([]byte, 16)
	parentID := make([]byte, 8)
	rand.Read(traceID)
	rand.Read(parentID)

	trace := fmt.Sprintf("%s-%s-1", hex.EncodeToString(traceID), hex.EncodeToString(parentID))
	baggage := fmt.Sprintf("sentry-environment=production,sentry-release=vQMo5GDY05ylXAQzFup_V,sentry-public_key=3f2904ecef7bc7859d6299eaf817040c,sentry-trace_id=%s,sentry-sample_rate=1,sentry-sampled=true", hex.EncodeToString(traceID))
	return trace, baggage
}

// ===================== FILE HELPERS =====================

func readLeadMs() int {
	data, err := os.ReadFile("lead.txt")
	if err != nil {
		log.Fatal("❌ lead.txt tidak ditemukan; fixed lead wajib tersedia")
	}

	values := strings.Fields(string(data))
	if len(values) == 0 {
		log.Fatal("❌ lead.txt kosong; isi satu angka milidetik, contoh -85")
	}

	ms, err := parseFixedLeadMs(values[0])
	if err != nil {
		log.Fatalf("❌ lead.txt tidak valid: %v", err)
	}
	if len(values) > 1 {
		logf("[LEAD] ⚠️ lead.txt berisi %d nilai; VPS ini memakai nilai pertama: %+dms\n", len(values), ms)
	}
	return ms
}

func parseFixedLeadMs(value string) (int, error) {
	parsed, err := strconv.ParseInt(value, 10, 32)
	if err != nil {
		return 0, fmt.Errorf("%q bukan integer milidetik: %w", value, err)
	}
	ms := int(parsed)
	if ms < MIN_FIXED_LEAD_MS || ms > MAX_FIXED_LEAD_MS {
		return 0, fmt.Errorf("%+dms di luar rentang aman [%+d,%+d]ms",
			ms, MIN_FIXED_LEAD_MS, MAX_FIXED_LEAD_MS)
	}
	return ms, nil
}

func leadToExecTarget(target time.Time, leadMs int) time.Time {
	return target.Add(time.Duration(leadMs) * time.Millisecond)
}

func spinWaitUntil(t time.Time) {
	for {
		remaining := time.Until(t)
		if remaining <= 0 {
			return
		}
		remainingMs := remaining.Milliseconds()
		if remainingMs > 25000 {
			time.Sleep(12 * time.Millisecond)
		} else if remainingMs > 10000 {
			time.Sleep(4 * time.Millisecond)
		} else if remainingMs > 4000 {
			time.Sleep(1500 * time.Microsecond)
		} else if remaining > 2*time.Millisecond {
			time.Sleep(200 * time.Microsecond)
		}
	}
}

type ClockHealth struct {
	ReferenceID     string
	LeapStatus      string
	Stratum         int
	SystemTimeSec   float64
	RMSOffsetSec    float64
	SkewPPM         float64
	RootDelaySec    float64
	RootDispSec     float64
	EstimatedErrMs  float64
	TrackingRawText string
}

type ClockLimits struct {
	OffsetMs float64
	RMSMs    float64
	BoundMs  float64
	SkewPPM  float64
}

var chronyNumberRE = regexp.MustCompile(`[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?`)

func firstNumber(value string) (float64, error) {
	match := chronyNumberRE.FindString(value)
	if match == "" {
		return 0, fmt.Errorf("angka tidak ditemukan pada %q", value)
	}
	return strconv.ParseFloat(match, 64)
}

func parseChronyTracking(raw string) (ClockHealth, error) {
	var h ClockHealth
	h.TrackingRawText = raw
	found := make(map[string]bool)

	for _, line := range strings.Split(raw, "\n") {
		parts := strings.SplitN(line, ":", 2)
		if len(parts) != 2 {
			continue
		}
		key := strings.ToLower(strings.TrimSpace(parts[0]))
		value := strings.TrimSpace(parts[1])

		switch key {
		case "reference id":
			fields := strings.Fields(value)
			if len(fields) == 0 {
				return ClockHealth{}, fmt.Errorf("reference ID kosong")
			}
			h.ReferenceID = fields[0]
			found[key] = true
		case "leap status":
			h.LeapStatus = value
			found[key] = true
		case "stratum":
			n, err := firstNumber(value)
			if err != nil {
				return ClockHealth{}, err
			}
			h.Stratum = int(n)
			found[key] = true
		case "system time":
			n, err := firstNumber(value)
			if err != nil {
				return ClockHealth{}, err
			}
			h.SystemTimeSec = n
			found[key] = true
		case "rms offset":
			n, err := firstNumber(value)
			if err != nil {
				return ClockHealth{}, err
			}
			h.RMSOffsetSec = n
			found[key] = true
		case "skew":
			n, err := firstNumber(value)
			if err != nil {
				return ClockHealth{}, err
			}
			h.SkewPPM = n
			found[key] = true
		case "root delay":
			n, err := firstNumber(value)
			if err != nil {
				return ClockHealth{}, err
			}
			h.RootDelaySec = n
			found[key] = true
		case "root dispersion":
			n, err := firstNumber(value)
			if err != nil {
				return ClockHealth{}, err
			}
			h.RootDispSec = n
			found[key] = true
		}
	}

	for _, required := range []string{
		"reference id", "leap status", "stratum", "system time", "rms offset", "skew",
		"root delay", "root dispersion",
	} {
		if !found[required] {
			return ClockHealth{}, fmt.Errorf("field chrony %q tidak ditemukan", required)
		}
	}

	h.EstimatedErrMs = (math.Abs(h.SystemTimeSec) +
		math.Abs(h.RootDispSec) +
		math.Abs(h.RootDelaySec)/2) * 1000
	return h, nil
}

func queryClockHealth(timeout time.Duration) (ClockHealth, error) {
	if timeout <= 0 {
		return ClockHealth{}, fmt.Errorf("budget query chrony habis")
	}
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()

	output, err := exec.CommandContext(ctx, "chronyc", "-n", "tracking").CombinedOutput()
	if err != nil {
		if ctx.Err() != nil {
			return ClockHealth{}, fmt.Errorf("chronyc tracking timeout: %w", ctx.Err())
		}
		return ClockHealth{}, fmt.Errorf("chronyc tracking gagal: %w (%s)", err, strings.TrimSpace(string(output)))
	}
	return parseChronyTracking(string(output))
}

func clockLimits() ClockLimits {
	return ClockLimits{
		OffsetMs: readClockLimitEnv(
			"WDP_MAX_CLOCK_OFFSET_MS",
			CLOCK_OFFSET_LIMIT_DEFAULT,
		),
		RMSMs: readClockLimitEnv(
			"WDP_MAX_CLOCK_RMS_MS",
			CLOCK_RMS_LIMIT_DEFAULT,
		),
		BoundMs: readClockBoundLimit(),
		SkewPPM: CLOCK_SKEW_LIMIT_PPM,
	}
}

func readClockBoundLimit() float64 {
	value := strings.TrimSpace(os.Getenv("WDP_MAX_CLOCK_BOUND_MS"))
	name := "WDP_MAX_CLOCK_BOUND_MS"
	if value == "" {
		// Kompatibilitas konfigurasi timing-v2.
		value = strings.TrimSpace(os.Getenv("WDP_MAX_CLOCK_ERROR_MS"))
		name = "WDP_MAX_CLOCK_ERROR_MS"
	}
	if value == "" {
		return CLOCK_BOUND_LIMIT_DEFAULT
	}
	limit, err := parseClockErrorLimitMs(value)
	if err != nil {
		log.Fatalf("❌ %s tidak valid: %v", name, err)
	}
	return limit
}

func readClockLimitEnv(name string, fallback float64) float64 {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	limit, err := parseClockErrorLimitMs(value)
	if err != nil {
		log.Fatalf("❌ %s tidak valid: %v", name, err)
	}
	return limit
}

func parseClockErrorLimitMs(value string) (float64, error) {
	limit, err := strconv.ParseFloat(value, 64)
	if err != nil || limit <= 0 || math.IsNaN(limit) || math.IsInf(limit, 0) {
		return 0, fmt.Errorf("%q wajib berupa angka finite > 0", value)
	}
	return limit, nil
}

func (h ClockHealth) verdict(limits ClockLimits) (bool, string) {
	if !strings.EqualFold(strings.TrimSpace(h.LeapStatus), "Normal") {
		return false, "Leap status bukan Normal"
	}
	refID := strings.ToUpper(strings.TrimPrefix(strings.TrimSpace(h.ReferenceID), "0X"))
	switch refID {
	case "", "00000000", "7F7F0101", "127.127.1.1", "127.0.0.1", "LOCAL", "LOCL":
		return false, fmt.Sprintf("reference ID tidak valid/local: %q", h.ReferenceID)
	}
	if h.Stratum <= 0 || h.Stratum > 15 {
		return false, fmt.Sprintf("stratum tidak sehat: %d", h.Stratum)
	}
	if math.Abs(h.SkewPPM) > limits.SkewPPM {
		return false, fmt.Sprintf("skew %.3fppm melewati %.1fppm", math.Abs(h.SkewPPM), limits.SkewPPM)
	}
	if math.Abs(h.SystemTimeSec)*1000 > limits.OffsetMs {
		return false, fmt.Sprintf("system time %.3fms melewati %.3fms", math.Abs(h.SystemTimeSec)*1000, limits.OffsetMs)
	}
	if math.Abs(h.RMSOffsetSec)*1000 > limits.RMSMs {
		return false, fmt.Sprintf("RMS offset %.3fms melewati %.3fms", math.Abs(h.RMSOffsetSec)*1000, limits.RMSMs)
	}
	if h.EstimatedErrMs > limits.BoundMs {
		return false, fmt.Sprintf("estimated clock error %.3fms melewati %.3fms", h.EstimatedErrMs, limits.BoundMs)
	}
	return true, "healthy"
}

func failClock(reason string) {
	reason = strings.Join(strings.Fields(reason), " ")
	fmt.Fprintf(os.Stderr, "__WDP_CLOCK_UNHEALTHY__ reason=%s\n", reason)
	os.Exit(78)
}

func requireClockHealth(maxWait time.Duration) ClockHealth {
	if runtime.GOOS != "linux" {
		logf("[CLOCK] Platform %s: gate chrony dilewati untuk development; VPS Linux tetap wajib chrony.\n", runtime.GOOS)
		return ClockHealth{LeapStatus: "development"}
	}
	if _, err := exec.LookPath("chronyc"); err != nil {
		failClock("chronyc tidak ditemukan; jalankan ulang installer GitHub agar chrony terpasang")
		return ClockHealth{}
	}
	if maxWait <= 0 {
		failClock("budget verifikasi clock habis sebelum chrony dapat diperiksa; VPS dibatalkan")
		return ClockHealth{}
	}

	limits := clockLimits()
	deadline := time.Now().Add(maxWait)
	var lastErr error
	var lastHealth ClockHealth

	for {
		remaining := time.Until(deadline)
		if remaining <= 0 {
			if lastHealth.LeapStatus != "" {
				failClock(fmt.Sprintf(
					"clock VPS tidak sehat: %v | leap=%s stratum=%d bound=%.3fms; VPS dibatalkan",
					lastErr, lastHealth.LeapStatus, lastHealth.Stratum, lastHealth.EstimatedErrMs,
				))
				return ClockHealth{}
			}
			failClock(fmt.Sprintf("clock VPS tidak dapat diverifikasi dalam %s: %v", maxWait, lastErr))
			return ClockHealth{}
		}
		queryTimeout := remaining
		if queryTimeout > 2500*time.Millisecond {
			queryTimeout = 2500 * time.Millisecond
		}

		health, err := queryClockHealth(queryTimeout)
		if err == nil {
			lastHealth = health
			if ok, reason := health.verdict(limits); ok {
				logf("[CLOCK] ✅ chrony sehat | ref=%s stratum=%d | system=%.3f/%.1fms | RMS=%.3f/%.1fms | skew=%.3f/%.1fppm | bound=%.3f/%.1fms\n",
					health.ReferenceID,
					health.Stratum,
					math.Abs(health.SystemTimeSec)*1000,
					limits.OffsetMs,
					math.Abs(health.RMSOffsetSec)*1000,
					limits.RMSMs,
					math.Abs(health.SkewPPM),
					limits.SkewPPM,
					health.EstimatedErrMs,
					limits.BoundMs)
				return health
			} else {
				lastErr = fmt.Errorf("%s", reason)
			}
		} else {
			lastErr = err
		}

		remaining = time.Until(deadline)
		if remaining <= 0 {
			if lastHealth.LeapStatus != "" {
				failClock(fmt.Sprintf(
					"clock VPS tidak sehat: %v | leap=%s stratum=%d bound=%.3fms; VPS dibatalkan",
					lastErr, lastHealth.LeapStatus, lastHealth.Stratum, lastHealth.EstimatedErrMs,
				))
				return ClockHealth{}
			}
			failClock(fmt.Sprintf("clock VPS tidak dapat diverifikasi: %v", lastErr))
			return ClockHealth{}
		}
		sleepFor := time.Second
		if remaining < sleepFor {
			sleepFor = remaining
		}
		time.Sleep(sleepFor)
	}
}

func anchorMonotonic(targetWall time.Time) time.Time {
	now := time.Now()
	return now.Add(targetWall.Sub(now))
}

func readTargetTime() time.Time {
	data, err := os.ReadFile("waktu.txt")
	if err != nil {
		log.Fatal("❌ waktu.txt tidak ditemukan!")
	}
	content := strings.TrimSpace(string(data))

	if target, err := time.Parse(time.RFC3339Nano, content); err == nil {
		if !target.After(time.Now()) {
			log.Fatalf("❌ Waktu absolut di waktu.txt sudah lewat: %s", content)
		}
		return target
	}

	re := regexp.MustCompile(`^(\d{1,2}):(\d{2})(?::(\d{2}))?$`)
	m := re.FindStringSubmatch(content)
	if m == nil {
		log.Fatal("❌ Format waktu.txt salah; gunakan HH:MM[:SS] atau RFC3339 ber-zona")
	}

	hour := atoi(m[1])
	minute := atoi(m[2])
	second := 0
	if len(m) > 3 && m[3] != "" {
		second = atoi(m[3])
	}
	if hour > 23 || minute > 59 || second > 59 {
		log.Fatalf("❌ Nilai waktu.txt di luar rentang: %s", content)
	}

	zoneName := strings.TrimSpace(os.Getenv("WDP_TIMEZONE"))
	if zoneName == "" {
		zoneName = "Asia/Jakarta"
	}
	location, err := time.LoadLocation(zoneName)
	if err != nil {
		log.Fatalf("❌ Timezone %q tidak tersedia: %v", zoneName, err)
	}

	now := time.Now().In(location)
	target := time.Date(now.Year(), now.Month(), now.Day(), hour, minute, second, 0, location)
	if target.Before(now) {
		target = time.Date(now.Year(), now.Month(), now.Day()+1, hour, minute, second, 0, location)
	}
	return target
}

func atoi(s string) int {
	var i int
	fmt.Sscanf(s, "%d", &i)
	return i
}

func loadOrders() []Order {
	data, err := os.ReadFile("user_server_wdp.txt")
	if err != nil {
		log.Fatal("❌ user_server_wdp.txt tidak ditemukan!")
	}

	var orders []Order
	seenUserIDs := make(map[string]bool)
	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.Split(line, "|")
		if len(parts) >= 2 {
			order := Order{
				UserID:   strings.TrimSpace(parts[0]),
				ServerID: strings.TrimSpace(parts[1]),
			}
			if order.UserID == "" || order.ServerID == "" {
				continue
			}
			if seenUserIDs[order.UserID] {
				logf("[ORDER] ⚠️ User ID duplikat dalam VPS dilewati: %s|%s\n", order.UserID, order.ServerID)
				continue
			}
			seenUserIDs[order.UserID] = true
			orders = append(orders, order)
		}
	}
	if len(orders) != MAX_USERS {
		log.Fatalf("❌ VPS wajib memiliki tepat %d User ID berbeda; ditemukan %d", MAX_USERS, len(orders))
	}
	logf("✅ Loaded %d order(s) (max %d)\n", len(orders), MAX_USERS)
	return orders
}

var outputFileMu sync.Mutex

// appendToFile safely appends a line to a file (used for output files)
func appendToFile(filename, content string) {
	outputFileMu.Lock()
	defer outputFileMu.Unlock()

	f, err := os.OpenFile(filename, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		logf("[WARN] Gagal buka %s: %v\n", filename, err)
		return
	}
	defer f.Close()
	f.WriteString(content)
}

// ===================== CAPTCHA (pakai net/http biasa, jarang dipanggil) =====================

func getFreshCaptcha(quiet bool) string {
	if !quiet {
		logf("[CAPTCHA] Mengambil token captcha baru dari Google...\n")
	}

	reloadBody, err := os.ReadFile("reload.txt")
	if err != nil {
		log.Fatalf("Gagal baca reload.txt: %v", err)
	}

	httpClient := &http.Client{Timeout: CAPTCHA_TIMEOUT}
	req, _ := http.NewRequest("POST", "https://www.google.com/recaptcha/api2/reload?k=6Le4GDcqAAAAAFTD31YUpEd1qMPgntTn1xFH7n_o", bytes.NewReader(reloadBody))
	req.Header.Set("sec-ch-ua-platform", `"Android"`)
	req.Header.Set("sec-ch-ua", sessionSecCH)
	req.Header.Set("content-type", "application/x-protobuffer")
	req.Header.Set("sec-ch-ua-mobile", "?1")
	req.Header.Set("user-agent", sessionUA)
	req.Header.Set("origin", "https://www.google.com")
	req.Header.Set("x-requested-with", "mark.via.gp")
	req.Header.Set("referer", "https://www.google.com/recaptcha/api2/anchor?ar=1&k=6Le4GDcqAAAAAFTD31YUpEd1qMPgntTn1xFH7n_o&co=aHR0cHM6Ly9nb3BheS5jby5pZDo0NDM.&hl=en&v=79clEdOi5xQbrrpL2L8kGmK3&size=invisible&anchor-ms=20000&execute-ms=30000&cb=34spuflel6ax")
	req.Header.Set("accept-language", "en-US,en;q=0.9")
	req.Header.Set("cookie", "_GRECAPTCHA=09AKhCRwjgcOklpqEngV5VzHCVLFDBttzjYVsQF9rHqCiF81J4gUV-koT2yYoYYMWQ65cGpZGNeDlgcD6AuDUHaXE; NID=530=KWlL-7aGLYQ7iV22k_iTZNjtlWxq7MMTpQq0u8sZfG2g5pM0duotIFiU3TGhRRcOdHcP6LZ4bYME6IegrhsnD0G9nKHB9cRSCGIRBj5W2Wyq8mVkj45oS7mt74yREaGoZGi_-AbUXLh2FE7NPNDvqLHmWFvEWrW_ZlapE-IZB7z36y_F6DCS_WYW5CRp6I_clI3zXw3f_XJAIVGOZJnq_UP7pDDvpsghYNmZCcgp96SxIonQxjlRKmrqaYFQ4FIwfCOHm36EKbA")

	resp, err := httpClient.Do(req)
	if err != nil {
		log.Fatalf("Gagal ambil captcha: %v", err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	re := regexp.MustCompile(`"rresp","([^"]+)"`)
	matches := re.FindStringSubmatch(string(body))
	if len(matches) < 2 {
		log.Fatal("Gagal parse captcha token")
	}

	token := matches[1]
	if !quiet {
		logf("[CAPTCHA] Token berhasil diambil (panjang: %d)\n\n", len(token))
	}
	return token
}

// ===================== BUILD HEADERS & BODY (untuk fasthttp) =====================

func setInquiryHeaders(req *fasthttp.Request, captchaToken string, cookies *sessionCookieJar) {
	trace, baggage := generateSentryTrace()

	h := &req.Header
	h.Set("sec-ch-ua-platform", `"Android"`)
	h.Set("authorization", "Bearer undefined")
	h.Set("sec-ch-ua", sessionSecCH)
	h.Set("sec-ch-ua-mobile", "?1")
	h.Set("baggage", baggage)
	h.Set("sentry-trace", trace)
	h.Set("user-agent", sessionUA)
	if captchaToken != "" {
		h.Set("x-captcha-token", captchaToken)
	} else {
		h.Del("x-captcha-token")
	}
	h.Set("content-type", "application/json")
	h.Set("x-client", "mobile")
	h.Set("accept", "*/*")
	h.Set("origin", "https://gopay.co.id")
	h.Set("x-requested-with", "mark.via.gp")
	h.Set("sec-fetch-site", "same-origin")
	h.Set("sec-fetch-mode", "cors")
	h.Set("sec-fetch-dest", "empty")
	h.Set("referer", "https://gopay.co.id/games/mobile-legends-bang-bang")
	h.Set("accept-language", "en-US,en;q=0.9")
	h.Set("x-timestamp", fmt.Sprintf("%d", time.Now().UnixMilli()))
	if cookies != nil {
		cookies.apply(h)
	}
}

func buildInquiryBody(order Order) []byte {
	body := map[string]interface{}{
		"productId":     19,
		"productItemId": 366,
		"data": map[string]string{
			"userId": order.UserID,
			"zoneId": order.ServerID,
		},
		"paymentChannelId": 73,
		"phoneNumber":      "628783219212",
		"voucher":          "WARWDPGG",
		"quantity":         1,
	}
	b, _ := json.Marshal(body)
	return b
}

// ===================== EXTRACT API MESSAGE (port dari PHP extractApiErrorMessage) =====================

// extractApiErrorMessage menggali pesan error/keterangan dari body response API
// dengan urutan prioritas yang SAMA PERSIS seperti war.php, supaya log Go bisa
// menampilkan pesan jelas (contoh: "voucher not active yet") bukan sekadar "retry".
func extractApiErrorMessage(body []byte) string {
	trimmed := strings.TrimSpace(string(body))
	if trimmed == "" {
		return ""
	}

	var payload map[string]interface{}
	if json.Unmarshal(body, &payload) != nil {
		// Bukan JSON valid → kembalikan raw body apa adanya.
		return trimmed
	}

	data, _ := payload["data"].(map[string]interface{})

	// Urutan coalesce identik dengan PHP:
	// errors[0].message → errors[0].message_title → data.errors[0].message
	// → data.errors[0].message_title → message → error → data.message → data.error
	if m := errArrayMessage(payload["errors"], "message"); m != "" {
		return m
	}
	if m := errArrayMessage(payload["errors"], "message_title"); m != "" {
		return m
	}
	if data != nil {
		if m := errArrayMessage(data["errors"], "message"); m != "" {
			return m
		}
		if m := errArrayMessage(data["errors"], "message_title"); m != "" {
			return m
		}
	}
	if m := getStr(payload, "message"); m != "" {
		return m
	}
	if m := getStr(payload, "error"); m != "" {
		return m
	}
	if data != nil {
		if m := getStr(data, "message"); m != "" {
			return m
		}
		if m := getStr(data, "error"); m != "" {
			return m
		}
	}

	// Fallback: kembalikan JSON utuh (mirip PHP yang json_encode payload).
	return trimmed
}

// errArrayMessage mengambil field tertentu dari elemen pertama sebuah array "errors".
func errArrayMessage(v interface{}, field string) string {
	arr, ok := v.([]interface{})
	if !ok || len(arr) == 0 {
		return ""
	}
	first, ok := arr[0].(map[string]interface{})
	if !ok {
		return ""
	}
	return getStr(first, field)
}

// getStr mengambil nilai string dari map (trim spasi), "" jika tidak ada/bukan string.
func getStr(m map[string]interface{}, key string) string {
	if m == nil {
		return ""
	}
	if v, ok := m[key]; ok {
		if s, ok := v.(string); ok {
			return strings.TrimSpace(s)
		}
	}
	return ""
}

// truncateRunes memotong string ke maksimal n karakter (rune-safe, tidak merusak UTF-8).
func truncateRunes(s string, n int) string {
	r := []rune(s)
	if len(r) > n {
		return string(r[:n])
	}
	return s
}

// ===================== CLASSIFY =====================

func classifyInquiryResponse(code int, body []byte) (status string, orderID string, errMsg string) {
	if (code == 200 || code == 201) && len(body) > 0 {
		var payload map[string]interface{}
		if json.Unmarshal(body, &payload) == nil {
			if data, ok := payload["data"].(map[string]interface{}); ok {
				if oid, ok := data["orderId"].(string); ok && oid != "" {
					return "success", oid, ""
				}
			}
			if oid, ok := payload["orderId"].(string); ok && oid != "" {
				return "success", oid, ""
			}
		}
	}

	// Pesan API yang sudah dirapikan (dipakai untuk semua verdict non-success).
	apiMsg := extractApiErrorMessage(body)

	msg := strings.ToLower(string(body))
	for _, p := range STOP_PATTERNS {
		if strings.Contains(msg, p) {
			return "stop", "", apiMsg
		}
	}
	for _, p := range INVALID_USER_PATTERNS {
		if strings.Contains(msg, p) {
			return "user_invalid", "", apiMsg
		}
	}
	for _, p := range SKIP_USER_PATTERNS {
		if strings.Contains(msg, p) {
			return "skip_user", "", apiMsg
		}
	}
	for _, p := range REGION_BLOCK_PATTERNS {
		if strings.Contains(msg, p) {
			return "region_block", "", apiMsg
		}
	}
	for _, p := range RETRY_PATTERNS {
		if strings.Contains(msg, p) {
			return "retry", "", apiMsg
		}
	}
	if code == 0 || (code >= 400 && code < 600) {
		// Tampilkan pesan API kalau ada, fallback ke kode HTTP.
		if apiMsg == "" {
			apiMsg = fmt.Sprintf("HTTP_%d", code)
		}
		return "retry", "", apiMsg
	}
	return "unknown", "", apiMsg
}

// ===================== DO INQUIRY (FASTHTTP - ZERO ALLOC STYLE) =====================

// fillInquiryRequest membangun request lengkap di luar hot path.
func fillInquiryRequest(req *fasthttp.Request, order Order, captchaToken string, lane *inquiryLane) {
	req.SetRequestURI("https://gopay.co.id/games/v1/order/inquiry")
	req.Header.SetMethod("POST")
	setInquiryHeaders(req, captchaToken, lane.cookies)
	req.SetBody(buildInquiryBody(order))
}

type prebuiltInquiry struct {
	Order   Order
	Request *fasthttp.Request
	Lane    *inquiryLane
}

func prebuildInquiryRequests(orders []Order, captchaToken string) []prebuiltInquiry {
	if len(orders) != len(inquiryLanes) {
		log.Fatalf("❌ Jumlah order (%d) tidak cocok dengan dedicated lane (%d)", len(orders), len(inquiryLanes))
	}

	prebuilt := make([]prebuiltInquiry, 0, len(orders))
	for i, o := range orders {
		req := fasthttp.AcquireRequest()
		lane := inquiryLanes[i]
		fillInquiryRequest(req, o, captchaToken, lane)
		prebuilt = append(prebuilt, prebuiltInquiry{Order: o, Request: req, Lane: lane})
	}
	return prebuilt
}

func responseHeaderString(h *fasthttp.ResponseHeader, names ...string) string {
	for _, name := range names {
		if value := h.Peek(name); len(value) > 0 {
			return string(value)
		}
	}
	return ""
}

func doInquiryPrebuilt(item prebuiltInquiry, deadline time.Time) InquiryResult {
	defer fasthttp.ReleaseRequest(item.Request)

	resp := fasthttp.AcquireResponse()
	defer fasthttp.ReleaseResponse(resp)

	callAt := time.Now()
	err := item.Lane.client.DoDeadline(item.Request, resp, deadline)
	doneAt := time.Now()

	transport := item.Lane.snapshot()
	item.Lane.finishFinal()

	result := InquiryResult{
		RTTMs:     durationMs(doneAt.Sub(callAt)),
		CallAt:    callAt,
		DoneAt:    doneAt,
		Transport: transport,
	}
	if err != nil {
		result.Status = "retry"
		result.ErrMsg = err.Error()
		return result
	}

	item.Lane.cookies.capture(&resp.Header)
	result.HTTPCode = resp.StatusCode()
	result.CFRay = responseHeaderString(&resp.Header, "cf-ray", "CF-Ray")
	result.Via = responseHeaderString(&resp.Header, "via", "Via")
	result.UpstreamMS = responseHeaderString(&resp.Header, "x-envoy-upstream-service-time", "X-Envoy-Upstream-Service-Time")
	result.RetryAfter = responseHeaderString(&resp.Header, "retry-after", "Retry-After")
	result.RateRemain = responseHeaderString(&resp.Header, "x-retry-remaining", "X-Retry-Remaining")
	result.RateLimit = responseHeaderString(&resp.Header, "x-ratelimit-limit", "X-RateLimit-Limit")
	result.Status, result.OrderID, result.ErrMsg = classifyInquiryResponse(resp.StatusCode(), resp.Body())
	return result
}

// ===================== SINGLE SALVO INQUIRY =====================

func durationMs(d time.Duration) float64 {
	return float64(d) / float64(time.Millisecond)
}

func offsetMs(at, target time.Time) float64 {
	if at.IsZero() || target.IsZero() {
		return 0
	}
	return durationMs(at.Sub(target))
}

func registerInquirySuccess(order Order, result InquiryResult, lane *inquiryLane) (SuccessEntry, bool) {
	if result.Status != "success" || result.OrderID == "" {
		return SuccessEntry{}, false
	}
	key := order.UserID + "|" + order.ServerID
	entry := SuccessEntry{Order: order, OrderID: result.OrderID, Lane: lane}
	mu.Lock()
	successMap[key] = entry
	mu.Unlock()
	return entry, true
}

// recordInquiryResult hanya mencatat observasi client-side. Tidak ada lagi
// estimasi "server arrival" dari RTT/2 karena itu bukan timestamp origin.
func recordInquiryResult(order Order, k string, result InquiryResult) {
	callOffset := offsetMs(result.CallAt, warStartWall)
	writeOffset := offsetMs(result.Transport.WriteAt, warStartWall)
	writeEndOffset := offsetMs(result.Transport.WriteEndAt, warStartWall)
	firstByteOffset := offsetMs(result.Transport.FirstByteAt, warStartWall)
	if result.Transport.WriteAt.IsZero() {
		writeOffset = callOffset
	}
	if result.Transport.WriteEndAt.IsZero() {
		writeEndOffset = writeOffset
	}
	fireOffset := writeOffset

	acquireMs := 0.0
	writeDurationMs := 0.0
	ttfbMs := 0.0
	if !result.Transport.WriteAt.IsZero() {
		acquireMs = durationMs(result.Transport.WriteAt.Sub(result.CallAt))
	}
	if !result.Transport.WriteAt.IsZero() && !result.Transport.WriteEndAt.IsZero() {
		writeDurationMs = durationMs(result.Transport.WriteEndAt.Sub(result.Transport.WriteAt))
	}
	if !result.Transport.WriteEndAt.IsZero() && !result.Transport.FirstByteAt.IsZero() {
		ttfbMs = durationMs(result.Transport.FirstByteAt.Sub(result.Transport.WriteEndAt))
	}

	mu.Lock()
	attemptStats = append(attemptStats, AttemptStat{
		User:            k,
		RTTMs:           result.RTTMs,
		FireOffset:      fireOffset,
		WriteOffset:     writeOffset,
		FirstByteOffset: firstByteOffset,
		TTFBMs:          ttfbMs,
		HTTPCode:        result.HTTPCode,
		Verdict:         result.Status,
		Reused:          result.Transport.Reused,
		ColdDial:        result.Transport.ColdDial,
		LateBlocked:     result.Transport.LateBlocked,
		BytesWritten:    result.Transport.BytesWritten,
	})
	mu.Unlock()

	tRel := durationMs(time.Since(warStartWall))
	connState := "cold"
	if result.Transport.Reused {
		connState = "reused"
	} else if result.Transport.ColdDial {
		connState = "reconnected"
	}
	if result.Transport.LateBlocked {
		connState = "late-blocked"
	}
	remote := result.Transport.RemoteAddr
	if remote == "" {
		remote = "unknown"
	}
	fireText := "n/a"
	writeEndText := "n/a"
	if result.Transport.BytesWritten > 0 {
		fireText = fmt.Sprintf("%+.3fms", fireOffset)
		writeEndText = fmt.Sprintf("%+.3fms", writeEndOffset)
	}
	detailTag := fmt.Sprintf(
		"[+%7.3fms][%s][try 1/1][rtt %.3fms][HTTP %d][fire %s][call%+.3fms][write-end %s][bytes %d][acquire %.3fms][write-dur %.3fms][ttfb %.3fms][conn %s][remote %s]",
		tRel, k, result.RTTMs, result.HTTPCode, fireText, callOffset,
		writeEndText, result.Transport.BytesWritten, acquireMs, writeDurationMs, ttfbMs, connState, remote,
	)

	shortErr := truncateRunes(result.ErrMsg, 80)
	if shortErr == "" {
		shortErr = "(no message)"
	}

	var logLines []string

	switch result.Status {
	case "success":
		logLines = append(logLines, fmt.Sprintf("%s ✅ OrderID: %s", detailTag, result.OrderID))

	case "stop":
		logLines = append(logLines, fmt.Sprintf("%s ⚠️ stop: %s", detailTag, shortErr))

	case "skip_user":
		logLines = append(logLines,
			fmt.Sprintf("%s ⚠️ skip_user: %s", detailTag, shortErr),
			fmt.Sprintf("[%s] ⏭️ SKIP USER: sudah pernah claim.", k))

	case "user_invalid":
		logLines = append(logLines,
			fmt.Sprintf("%s ❌ user_invalid: %s", detailTag, shortErr),
			fmt.Sprintf("[%s] ❌ USER ID SALAH.", k))

	case "region_block":
		logLines = append(logLines,
			fmt.Sprintf("%s ⚠️ region_block: %s", detailTag, shortErr),
			fmt.Sprintf("[%s] 🌐 USER ID DILUAR REGION.", k))

	default:
		logLines = append(logLines, fmt.Sprintf("%s ⚠️ %s: %s → final single-shot", detailTag, result.Status, shortErr))
	}

	edgeParts := make([]string, 0, 6)
	if result.CFRay != "" {
		edgeParts = append(edgeParts, "cf-ray="+result.CFRay)
	}
	if result.UpstreamMS != "" {
		edgeParts = append(edgeParts, "upstream-ms="+result.UpstreamMS)
	}
	if result.RateRemain != "" {
		edgeParts = append(edgeParts, "remaining="+result.RateRemain)
	}
	if result.RateLimit != "" {
		edgeParts = append(edgeParts, "limit="+result.RateLimit)
	}
	if result.RetryAfter != "" {
		edgeParts = append(edgeParts, "retry-after="+result.RetryAfter)
	}
	if result.Via != "" {
		edgeParts = append(edgeParts, "via="+truncateRunes(result.Via, 120))
	}
	if len(edgeParts) > 0 {
		logLines = append(logLines, fmt.Sprintf("[%s][EDGE] %s", k, strings.Join(edgeParts, " | ")))
	}

	for _, ln := range logLines {
		logf("%s\n", ln)
	}
}

type singleSalvo struct {
	startCh    chan struct{}
	postFireCh chan struct{}
	jobs       []prebuiltInquiry
	deadline   time.Time
	done       sync.WaitGroup
	writeAck   sync.WaitGroup
	payments   *paymentPipeline
}

func prepareSingleSalvo(prebuilt []prebuiltInquiry, payments *paymentPipeline, deadline time.Time) *singleSalvo {
	salvo := &singleSalvo{
		startCh:    make(chan struct{}),
		postFireCh: make(chan struct{}),
		jobs:       prebuilt,
		deadline:   deadline,
		payments:   payments,
	}
	key := func(o Order) string { return o.UserID + "|" + o.ServerID }

	var ready sync.WaitGroup
	ready.Add(len(prebuilt))

	for _, job := range prebuilt {
		salvo.done.Add(1)
		go func(item prebuiltInquiry) {
			defer salvo.done.Done()
			order := item.Order
			k := key(order)

			ready.Done()
			<-salvo.startCh

			result := doInquiryPrebuilt(item, salvo.deadline)
			<-salvo.postFireCh

			if entry, ok := registerInquirySuccess(order, result, item.Lane); ok {
				salvo.payments.submit(entry)
			}
			recordInquiryResult(order, k, result)
		}(job)
	}

	ready.Wait()
	return salvo
}

func armSingleSalvo(salvo *singleSalvo, execTarget time.Time) {
	timestamp := strconv.FormatInt(time.Now().UnixMilli(), 10)
	cutoff := execTarget.Add(MAX_RELEASE_LATE)
	salvo.writeAck.Add(len(salvo.jobs))
	for _, job := range salvo.jobs {
		job.Request.Header.Set("x-timestamp", timestamp)
		job.Lane.armFinal(&salvo.writeAck, cutoff)
	}
}

func fireSingleSalvo(salvo *singleSalvo, execTarget time.Time, orderCount int) []SuccessEntry {
	spinWaitUntil(execTarget)

	releasedAt := time.Now()
	if late := releasedAt.Sub(execTarget); late > MAX_RELEASE_LATE {
		log.Fatalf("❌ Release terlambat %.3fms (batas %.3fms); VPS ini dibatalkan agar tidak mengirim salvo jauh dari target",
			durationMs(late), durationMs(MAX_RELEASE_LATE))
	}
	close(salvo.startCh)
	salvo.writeAck.Wait()
	allWritesAt := time.Now()
	close(salvo.postFireCh)

	logf("🚀 SINGLE SALVO RELEASE: %d user lepas bersamaan @ [%s]\n",
		orderCount, releasedAt.Format("15:04:05.000000"))
	logf("[TIMING] release=%+.3fms | seluruh lane memasuki first TLS application write dalam %.3fms | tidak ada log/file I/O di antaranya\n",
		durationMs(releasedAt.Sub(execTarget)),
		durationMs(allWritesAt.Sub(releasedAt)))

	salvo.done.Wait()

	mu.Lock()
	defer mu.Unlock()
	var results []SuccessEntry
	for _, v := range successMap {
		results = append(results, v)
	}
	return results
}

// ===================== PAYMENT (FASTHTTP) =====================

type paymentPipeline struct {
	jobs    chan SuccessEntry
	workers sync.WaitGroup
	success atomic.Int32
}

func startPaymentPipeline() *paymentPipeline {
	p := &paymentPipeline{jobs: make(chan SuccessEntry, MAX_USERS)}
	for i := 0; i < MAX_USERS; i++ {
		p.workers.Add(1)
		go func() {
			defer p.workers.Done()
			for entry := range p.jobs {
				if processPayment(entry) {
					p.success.Add(1)
				}
			}
		}()
	}
	return p
}

func (p *paymentPipeline) submit(entry SuccessEntry) {
	p.jobs <- entry
}

func (p *paymentPipeline) closeAndWait() int {
	close(p.jobs)
	p.workers.Wait()
	return int(p.success.Load())
}

func processPayment(e SuccessEntry) bool {
	k := e.Order.UserID + "|" + e.Order.ServerID
	orderID := e.OrderID
	hash := sha256.Sum256([]byte(orderID))
	ref := hex.EncodeToString(hash[:])[:32]

	req := fasthttp.AcquireRequest()
	defer fasthttp.ReleaseRequest(req)
	resp := fasthttp.AcquireResponse()
	defer fasthttp.ReleaseResponse(resp)

	payBody, _ := json.Marshal(map[string]interface{}{
		"orderId":            orderID,
		"paymentChannelId":   73,
		"phoneNumber":        "628783219212",
		"paymentPhoneNumber": "",
		"quantity":           1,
		"invoiceUrl":         "https://gopay.co.id/games/payment/",
	})

	req.SetRequestURI("https://gopay.co.id/games/v1/order/payment")
	req.Header.SetMethod("POST")
	setInquiryHeaders(req, "", e.Lane.cookies)
	req.Header.Set("x-request-reference", ref)
	req.Header.Set("x-request-id", ref)
	req.Header.Set("idempotency-key", ref)
	req.SetBody(payBody)

	if err := e.Lane.client.DoTimeout(req, resp, time.Duration(PAYMENT_TIMEOUT_MS)*time.Millisecond); err != nil {
		logf("[%s] Payment error: %v\n", k, err)
		return false
	}
	e.Lane.cookies.capture(&resp.Header)
	if resp.StatusCode() != 200 && resp.StatusCode() != 201 {
		payErr := truncateRunes(extractApiErrorMessage(resp.Body()), 120)
		if payErr != "" {
			logf("[%s] Payment HTTP %d - %s\n", k, resp.StatusCode(), payErr)
		} else {
			logf("[%s] Payment HTTP %d\n", k, resp.StatusCode())
		}
		return false
	}

	var payRes map[string]interface{}
	if json.Unmarshal(resp.Body(), &payRes) != nil {
		return false
	}
	txnID, _ := payRes["data"].(string)
	if txnID == "" {
		return false
	}

	txnData := getTransactionUntilReady(txnID, e.Lane)
	if txnData == nil {
		return false
	}

	payURL := transactionPaymentURL(txnData)
	if payURL == "" {
		logf("[%s] Payment belum siap: URL pembayaran kosong\n", k)
		return false
	}
	appendToFile("transaksi_url.txt", fmt.Sprintf("%s|%s\n", k, "https://gopay.co.id/games/payment/"+txnID))
	appendToFile("deeplinks.txt", payURL+"\n")
	appendToFile("order_ids.txt", fmt.Sprintf("%s|%s|%s\n", k, orderID, payURL))
	logf("[%s] ✅ SUCCESS | Pay URL tersedia\n", k)
	return true
}

func transactionPaymentURL(data map[string]interface{}) string {
	ap, ok := data["actionPayment"].(map[string]interface{})
	if !ok {
		return ""
	}
	for _, key := range []string{"paymentDirect", "deeplinkRedirect"} {
		if value, ok := ap[key].(string); ok {
			if value = strings.TrimSpace(value); value != "" {
				return value
			}
		}
	}
	return ""
}

func getTransactionUntilReady(txnID string, lane *inquiryLane) map[string]interface{} {
	delays := []time.Duration{90, 120, 160, 220, 300, 420, 560, 750, 950}
	deadline := time.Now().Add(TRANSACTION_POLL_TIMEOUT)

	for _, d := range delays {
		sleepFor := d * time.Millisecond
		if time.Until(deadline) <= sleepFor {
			return nil
		}
		time.Sleep(sleepFor)

		req := fasthttp.AcquireRequest()
		resp := fasthttp.AcquireResponse()

		req.SetRequestURI("https://gopay.co.id/games/v1/transaction/" + txnID)
		req.Header.SetMethod("GET")
		setInquiryHeaders(req, "", lane.cookies)

		err := lane.client.DoDeadline(req, resp, deadline)
		if err == nil {
			lane.cookies.capture(&resp.Header)
		}
		var data map[string]interface{}
		if err == nil && json.Unmarshal(resp.Body(), &data) == nil {
			if transactionPaymentURL(data) != "" {
				fasthttp.ReleaseRequest(req)
				fasthttp.ReleaseResponse(resp)
				return data
			}
		}
		fasthttp.ReleaseRequest(req)
		fasthttp.ReleaseResponse(resp)
		if err != nil {
			continue
		}
	}
	return nil
}

// ===================== TIMING & CONNECTION PREFLIGHT =====================

func waitUntilPreWar(target time.Time, secondsBefore int) {
	testTs := target.Add(-time.Duration(secondsBefore) * time.Second)

	if diff := time.Until(testTs); diff > 0 {
		logf("Menunggu fase persiapan (T-%ds sebelum war)...\n", secondsBefore)
		time.Sleep(diff)
	} else {
		logf("[TIMING] T-%ds sudah lewat; persiapan dijalankan sekarang.\n", secondsBefore)
	}
}

type laneWarmResult struct {
	LaneID int
	HTTP   int
	RTTMs  float64
	Remote string
	ConnID int64
	Err    error
}

// warmLane menguji HTTP keep-alive melalui resource publik non-mutating.
// Tidak ada request ke endpoint inquiry dan tidak ada captcha yang dikonsumsi.
func warmLane(lane *inquiryLane, timeout time.Duration) laneWarmResult {
	result := laneWarmResult{LaneID: lane.id}
	req := fasthttp.AcquireRequest()
	defer fasthttp.ReleaseRequest(req)
	resp := fasthttp.AcquireResponse()
	defer fasthttp.ReleaseResponse(resp)

	req.SetRequestURI("https://gopay.co.id/robots.txt")
	req.Header.SetMethod("GET")
	req.Header.Set("user-agent", sessionUA)
	req.Header.Set("accept", "text/plain,*/*")
	req.Header.Set("accept-language", "en-US,en;q=0.9")
	req.Header.Set("connection", "keep-alive")
	lane.cookies.apply(&req.Header)

	start := time.Now()
	err := lane.client.DoTimeout(req, resp, timeout)
	result.RTTMs = durationMs(time.Since(start))
	if err != nil {
		result.Err = err
		return result
	}
	result.HTTP = resp.StatusCode()
	lane.cookies.capture(&resp.Header)
	if resp.Header.ConnectionClose() {
		result.Err = fmt.Errorf("robots.txt HTTP %d menutup koneksi warm", resp.StatusCode())
		return result
	}

	lane.warmConnID = lane.seq.Load()
	lane.metaMu.Lock()
	result.Remote = lane.remoteAddr
	lane.metaMu.Unlock()
	result.ConnID = lane.warmConnID
	if result.ConnID == 0 {
		result.Err = fmt.Errorf("connection generation tidak tercatat")
	}
	return result
}

func warmDedicatedLanes(timeout time.Duration) int {
	logf("[CONN] T-5s: validasi %d dedicated TLS lane via GET /robots.txt (deadline %dms; tanpa inquiry)\n",
		len(inquiryLanes), timeout.Milliseconds())

	results := make(chan laneWarmResult, len(inquiryLanes))
	for _, lane := range inquiryLanes {
		go func(l *inquiryLane) {
			results <- warmLane(l, timeout)
		}(lane)
	}

	okCount := 0
	for range inquiryLanes {
		result := <-results
		if result.Err != nil {
			logf("[CONN] ⚠️ lane=%d warm gagal rtt=%.3fms: %v; final akan tercatat cold/reconnect\n",
				result.LaneID, result.RTTMs, result.Err)
			continue
		}
		okCount++
		logf("[CONN] ✅ lane=%d conn=%d remote=%s HTTP=%d rtt=%.3fms reusable\n",
			result.LaneID, result.ConnID, result.Remote, result.HTTP, result.RTTMs)
	}
	return okCount
}

func runFixedLeadSingleSalvo(target time.Time, leadMs int, orders []Order) ([]SuccessEntry, int) {
	warStartWall = target
	captchaAt := target.Add(-CAPTCHA_LEAD)
	preconnectAt := target.Add(-PRECONNECT_LEAD)
	prebuildAt := target.Add(-PREBUILD_LEAD)
	armAt := target.Add(-ARM_LEAD)
	execTarget := leadToExecTarget(target, leadMs)
	if !execTarget.After(armAt) {
		log.Fatalf("❌ Fixed lead %+dms mendahului fase arm T-%s", leadMs, ARM_LEAD)
	}

	logf("⏰ Target T=0: %s | zone=%s | fixed lead.txt: %+dms | exec: %s\n",
		target.Format(time.RFC3339Nano), target.Location(), leadMs, execTarget.Format("15:04:05.000000"))

	if time.Now().Before(captchaAt) {
		spinWaitUntil(captchaAt)
	} else {
		logf("[CAPTCHA] T-10s sudah lewat; token diambil sekarang dengan timeout %s.\n", CAPTCHA_TIMEOUT)
	}
	captcha := getFreshCaptcha(false)
	logf("[CAPTCHA] Token final siap pada T%+.3fms.\n", durationMs(time.Now().Sub(target)))

	if time.Now().Before(preconnectAt) {
		spinWaitUntil(preconnectAt)
	} else {
		logf("[CONN] T-5s sudah lewat; preconnect dijalankan jika budget masih aman.\n")
	}

	warmBudget := time.Until(prebuildAt) - 250*time.Millisecond
	if warmBudget > PRECONNECT_TIMEOUT {
		warmBudget = PRECONNECT_TIMEOUT
	}
	if warmBudget >= 200*time.Millisecond {
		warmed := warmDedicatedLanes(warmBudget)
		logf("[CONN] %d/%d lane terbukti reusable; tidak ada fallback inquiry warm-up.\n", warmed, len(inquiryLanes))
	} else {
		logf("[CONN] Preconnect dilewati: sisa budget hanya %dms.\n", warmBudget.Milliseconds())
	}

	spinWaitUntil(prebuildAt)
	prebuilt := prebuildInquiryRequests(orders, captcha)
	payments := startPaymentPipeline()
	inquiryDeadline := execTarget.Add(time.Duration(INQUIRY_TIMEOUT_MS) * time.Millisecond)
	salvo := prepareSingleSalvo(prebuilt, payments, inquiryDeadline)
	oldGCPercent := debug.SetGCPercent(-1)
	runtime.GC()
	defer debug.SetGCPercent(oldGCPercent)
	logf("[PREBUILD] T-2s: %d request + seluruh worker siap; forced GC selesai dan automatic GC dipause sampai flow selesai.\n", len(orders))

	spinWaitUntil(armAt)
	armSingleSalvo(salvo, execTarget)

	successes := fireSingleSalvo(salvo, execTarget, len(orders))
	paymentSuccess := payments.closeAndWait()
	return successes, paymentSuccess
}

// printTimingSummary - Analisis timing untuk kalibrasi lead.txt
func printTimingSummary() {
	if len(attemptStats) == 0 {
		return
	}

	logf("\n📈 ========== TIMING SUMMARY (client-side, bukan origin arrival) ==========\n")

	var rtts []float64
	var offsets []float64
	var ttfbs []float64
	stopCount := 0
	reusedCount := 0
	coldCount := 0
	lateBlockedCount := 0
	for _, s := range attemptStats {
		rtts = append(rtts, s.RTTMs)
		if s.BytesWritten > 0 {
			offsets = append(offsets, s.FireOffset)
		}
		if s.TTFBMs > 0 {
			ttfbs = append(ttfbs, s.TTFBMs)
		}
		if s.Verdict == "stop" {
			stopCount++
		}
		if s.Reused {
			reusedCount++
		}
		if s.ColdDial {
			coldCount++
		}
		if s.LateBlocked {
			lateBlockedCount++
		}
	}

	minRTT, medRTT, maxRTT := minMedMax(rtts)
	minOff, medOff, maxOff := minMedMax(offsets)
	minTTFB, medTTFB, maxTTFB := minMedMax(ttfbs)
	if len(offsets) > 0 {
		logf("   Salvo #1 (orders=%d writes=%d): RTT[min=%.3f med=%.3f max=%.3f] | first TLS-app write[min=%+.3f med=%+.3f max=%+.3f] | TTFB[min=%.3f med=%.3f max=%.3f]\n",
			len(attemptStats), len(offsets), minRTT, medRTT, maxRTT, minOff, medOff, maxOff, minTTFB, medTTFB, maxTTFB)
	} else {
		logf("   Salvo #1 (orders=%d writes=0): tidak ada byte inquiry yang dikirim | RTT[min=%.3f med=%.3f max=%.3f]\n",
			len(attemptStats), minRTT, medRTT, maxRTT)
	}
	logf("   Connection reuse=%d/%d | cold/reconnect=%d | late-blocked=%d | STOP=%d | retry inquiry=OFF\n",
		reusedCount, len(attemptStats), coldCount, lateBlockedCount, stopCount)

	firstStop := 0.0
	hasStop := false
	for _, s := range attemptStats {
		if s.Verdict == "stop" {
			firstStop = s.FireOffset
			hasStop = true
			break
		}
	}

	if hasStop {
		logf("\n   → First OUT-OF-STOCK berasal dari request dengan first TLS-app write %+.3fms.\n", firstStop)
		logf("   → Ini timestamp masuk tls.Conn.Write di client, bukan timestamp NIC/origin.\n")
	} else {
		logf("\n   → Tidak ada respons 'out of stock' pada single salvo ini.\n")
	}

	logf("============================================================\n\n")
}

func minMedMax(vals []float64) (min, med, max float64) {
	if len(vals) == 0 {
		return 0, 0, 0
	}
	sort.Float64s(vals)
	min = vals[0]
	max = vals[len(vals)-1]
	med = vals[len(vals)/2]
	return
}

var logFile *os.File
var logger *log.Logger

func initLogging() {
	var err error
	logFile, err = os.OpenFile("loghasil.txt", os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0644)
	if err != nil {
		logFile = nil
		logger = log.New(os.Stdout, "", log.LstdFlags)
		return
	}
	mw := io.MultiWriter(os.Stdout, logFile)
	logger = log.New(mw, "", log.LstdFlags)
}

func logf(format string, v ...interface{}) {
	logger.Printf(format, v...)
}

// ===================== MAIN =====================

func main() {
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "--version":
			fmt.Printf("wdp-war %s %s/%s\n", PROGRAM_VERSION, runtime.GOOS, runtime.GOARCH)
			return
		case "--check-clock":
			logger = log.New(os.Stdout, "", 0)
			requireClockHealth(CLOCK_WAIT_MAX)
			fmt.Println("__WDP_CLOCK_HEALTHY__")
			return
		default:
			fmt.Fprintf(os.Stderr, "argumen tidak dikenal: %s\n", os.Args[1])
			os.Exit(2)
		}
	}

	initLogging()
	if logFile != nil {
		defer logFile.Close()
	}

	logf("=== GOPAY MLBB WDP WAR (GOLANG + FASTHTTP) ===\n")
	logf("VERSION=%s | MODE=SINGLE_SALVO | MAX_USERS=%d | RETRY=OFF | LANES=4xH1 | LEAD=FIXED\n\n",
		PROGRAM_VERSION, MAX_USERS)

	orders := loadOrders()
	targetWall := readTargetTime()
	leadMs := readLeadMs()
	logf("⚡ Lead offset: %+dms (fixed dari lead.txt)\n\n", leadMs)

	// Gate pertama menjaga proses yang dijalankan jauh sebelum target.
	requireClockHealth(CLOCK_WAIT_MAX)
	waitUntilPreWar(targetWall, 60)

	// Gate kedua dilakukan dekat event, lalu wall target di-anchor ke monotonic.
	secondBudget := CLOCK_WAIT_MAX
	if safe := time.Until(targetWall) - 10*time.Second; safe < secondBudget {
		secondBudget = safe
	}
	if secondBudget < 0 {
		secondBudget = 0
	}
	requireClockHealth(secondBudget)
	target := anchorMonotonic(targetWall)
	logf("[CLOCK] Deadline T=0 sudah di-anchor ke monotonic clock.\n")

	initFastHTTPClients()
	inquirySuccess, paymentSuccess := runFixedLeadSingleSalvo(target, leadMs, orders)

	logf("\n📊 Inquiry selesai: %d/%d sukses\n", len(inquirySuccess), len(orders))

	printTimingSummary()

	logf("\n🏁 FULL FLOW SELESAI! Berhasil: %d / %d\n", paymentSuccess, len(orders))
	logf("Lihat: deeplinks.txt, order_ids.txt, transaksi_url.txt, loghasil.txt\n")
}
