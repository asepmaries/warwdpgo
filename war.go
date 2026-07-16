package main

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math"
	"net/http"
	"os"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/valyala/fasthttp"
)

// ======================================================================
// GOPAY MLBB WDP - WAR EDITION (GOLANG + FASTHTTP)
// Target: MAX 5 users | MAX 10 retry | Warm-up 5 paralel | Timing akurat (mirip PHP)
// ======================================================================

const (
	MAX_USERS                 = 5
	MAX_RETRY_PER_USER        = 10
	MAX_TOTAL_INQUIRIES       = 12 // Turunkan agar aman dari 429 (maksimal ~10-12 inquiry total)
	MINI_PROBE2_LEAD_MS       = 1500
	MINI_PROBE2_PARALLEL      = 5 // Samakan dengan MAX_USERS supaya semua koneksi warm
	INQUIRY_TIMEOUT_MS        = 5200
	PAYMENT_TIMEOUT_MS        = 5200
	PREWAR_TEST_COUNT         = 8
	TARGET_SRV_MS_DEFAULT     = -10.0   // target srv (ms setelah T=0) untuk dynamic lead
	FINE_TUNE_START_BEFORE_MS = 12000 // H-12s: mulai fase fine-tune
	FINE_TUNE_PROBE_INTERVAL  = 1000 * time.Millisecond
	FINE_TUNE_PROBE_COUNT     = 8 // 8x @ 1/detik: H-12s → H-5s
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
	mu             sync.Mutex
	stopGlobal     bool
	totalInquiries int
	successMap     = make(map[string]SuccessEntry)
	skipUsers      = make(map[string]bool)
	regionBlocked  = make(map[string]bool)
	attempts       = make(map[string]int)
	eventLog       []Event

	// Untuk summary timing (mirip PHP)
	attemptStats    []AttemptStat
	warStartWall    time.Time // Waktu target asli dari waktu.txt (T=0 sebelum lead)
	warmupMedianRTT float64   // Median RTT dari warm-up probe (info)
	warmupMinRTT    float64   // RTT min dari warm-up H-1.5s (untuk lead/ow_est)
	fineTuneRTTs    []float64 // RTT fine-tune H-12..H-5 (fallback lead jika warm-up min spike)

	// Kalibrasi jam server: serverWallMs = localWallMs + serverClockOffsetMs.
	// Positif = jam server LEBIH CEPAT dari jam VPS (VPS ketinggalan).
	serverClockOffsetMs float64
	serverClockKnown    bool
)

// clockSample: satu observasi jam server (detik dari header Date) terhadap
// jam VPS pada momen response kira-kira dibuat server (start + rtt/2).
type clockSample struct {
	localMs float64 // instant pembuatan response menurut jam VPS (ms epoch)
	sec     int64   // detik epoch dari header Date server
}

type Order struct {
	UserID   string
	ServerID string
}

type SuccessEntry struct {
	Order   Order
	OrderID string
}

type Event struct {
	TRelMs float64
	Label  string
}

type InquiryResult struct {
	Status   string
	OrderID  string
	ErrMsg   string
	HTTPCode int
	RTTMs    float64
}

// AttemptStat untuk analisis timing & kalibrasi lead
type AttemptStat struct {
	User       string
	Try        int
	RTTMs      float64
	FireOffset float64 // relative to target burst (ms)
	HTTPCode   int
	Verdict    string
}

// ===================== FASTHTTP CLIENT (MAX PERFORMANCE) =====================
var fastClient *fasthttp.Client

func initFastHTTPClient() {
	fastClient = &fasthttp.Client{
		MaxConnsPerHost:               150,
		MaxIdleConnDuration:           120 * time.Second,
		ReadTimeout:                   time.Duration(INQUIRY_TIMEOUT_MS) * time.Millisecond,
		WriteTimeout:                  time.Duration(INQUIRY_TIMEOUT_MS) * time.Millisecond,
		MaxConnWaitTimeout:            3 * time.Second,
		DisableHeaderNamesNormalizing: true,
		DisablePathNormalizing:        true,
		Dial: (&fasthttp.TCPDialer{
			Concurrency:      2000,
			DNSCacheDuration: 10 * time.Minute,
		}).Dial,
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

func formatMicrotimeNow() string {
	now := time.Now()
	return now.Format("15:04:05.000000")
}

// ===================== FILE HELPERS =====================

func readLeadMs() int {
	data, err := os.ReadFile("lead.txt")
	if err != nil {
		logf("[LEAD] lead.txt tidak ditemukan, pakai default 0ms\n")
		return 0
	}
	var ms int
	fmt.Sscanf(strings.TrimSpace(string(data)), "%d", &ms)
	return ms
}

// readTargetSrvMs membaca target srv (ms setelah T=0) dari target_srv.txt (fallback: target_arr.txt).
func readTargetSrvMs() float64 {
	for _, name := range []string{"target_srv.txt", "target_arr.txt"} {
		data, err := os.ReadFile(name)
		if err != nil {
			continue
		}
		var v float64
		if _, err := fmt.Sscanf(strings.TrimSpace(string(data)), "%f", &v); err == nil {
			return v
		}
	}
	return TARGET_SRV_MS_DEFAULT
}

func leadToExecTarget(target time.Time, leadMs int) time.Time {
	if leadMs >= 0 {
		return target.Add(time.Duration(leadMs) * time.Millisecond)
	}
	return target.Add(-time.Duration(-leadMs) * time.Millisecond)
}

func avgRTT(rtts []float64) float64 {
	if len(rtts) == 0 {
		return 0
	}
	var sum float64
	for _, r := range rtts {
		sum += r
	}
	return sum / float64(len(rtts))
}

// effectiveLeadRTT memilih RTT untuk ow_est / lead dinamis.
// Default: warmupMinRTT. Jika warm-up T-1.5s spike (> rata-rata fine-tune), pakai rata-rata fine-tune.
func effectiveLeadRTT() (rtt float64, usedFineTuneAvg bool) {
	if warmupMinRTT <= 0 {
		if warmupMedianRTT > 0 {
			return warmupMedianRTT, false
		}
		return 0, false
	}
	ftAvg := avgRTT(fineTuneRTTs)
	if ftAvg > 0 && warmupMinRTT > ftAvg {
		return ftAvg, true
	}
	return warmupMinRTT, false
}

// resolveLeadMs: lead = target_srv - ow_est, ow_est = effectiveLeadRTT / 2.
func resolveLeadMs(fallbackLeadMs int, targetSrv float64) int {
	leadRTT, usedTune := effectiveLeadRTT()
	owEst := leadRTT / 2.0
	if owEst <= 0 {
		logf("[LEAD] Warm-up RTT tidak tersedia → pakai lead.txt: %dms\n", fallbackLeadMs)
		return fallbackLeadMs
	}
	dynamic := int(math.Round(targetSrv - owEst))
	if usedTune {
		logf("[LEAD] Dynamic: target_srv=+%.0fms | RTT_min=%.0fms > tune_avg=%.0fms → pakai tune_avg | ow_est=%.0fms → lead=%+dms\n",
			targetSrv, warmupMinRTT, leadRTT, owEst, dynamic)
	} else {
		logf("[LEAD] Dynamic: target_srv=+%.0fms | RTT_min=%.0fms | ow_est=%.0fms → lead=%+dms\n",
			targetSrv, warmupMinRTT, owEst, dynamic)
	}
	logf("[LEAD] (lead.txt=%dms hanya dipakai sebagai fallback)\n", fallbackLeadMs)
	return dynamic
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

func readTargetTime() time.Time {
	data, err := os.ReadFile("waktu.txt")
	if err != nil {
		log.Fatal("❌ waktu.txt tidak ditemukan!")
	}
	content := strings.TrimSpace(string(data))

	re := regexp.MustCompile(`^(\d{1,2}):(\d{2})(?::(\d{2}))?$`)
	m := re.FindStringSubmatch(content)
	if m == nil {
		log.Fatal("❌ Format waktu.txt salah!")
	}

	hour := atoi(m[1])
	minute := atoi(m[2])
	second := 0
	if len(m) > 3 && m[3] != "" {
		second = atoi(m[3])
	}

	now := time.Now()
	target := time.Date(now.Year(), now.Month(), now.Day(), hour, minute, second, 0, time.Local)
	if target.Before(now) {
		target = target.Add(24 * time.Hour)
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
	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.Split(line, "|")
		if len(parts) >= 2 {
			orders = append(orders, Order{
				UserID:   strings.TrimSpace(parts[0]),
				ServerID: strings.TrimSpace(parts[1]),
			})
		}
	}
	if len(orders) > MAX_USERS {
		orders = orders[:MAX_USERS]
	}
	logf("✅ Loaded %d order(s) (max %d)\n", len(orders), MAX_USERS)
	return orders
}

// appendToFile safely appends a line to a file (used for output files)
func appendToFile(filename, content string) {
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

	_, secCh := getRandomUserAgent()

	httpClient := &http.Client{Timeout: 10 * time.Second}
	req, _ := http.NewRequest("POST", "https://www.google.com/recaptcha/api2/reload?k=6Le4GDcqAAAAAFTD31YUpEd1qMPgntTn1xFH7n_o", bytes.NewReader(reloadBody))
	req.Header.Set("sec-ch-ua-platform", `"Android"`)
	req.Header.Set("sec-ch-ua", secCh)
	req.Header.Set("content-type", "application/x-protobuffer")
	req.Header.Set("sec-ch-ua-mobile", "?1")
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

func setInquiryHeaders(req *fasthttp.Request, captchaToken string) {
	ua, secCh := getRandomUserAgent()
	trace, baggage := generateSentryTrace()

	h := &req.Header
	h.Set("sec-ch-ua-platform", `"Android"`)
	h.Set("authorization", "Bearer undefined")
	h.Set("sec-ch-ua", secCh)
	h.Set("sec-ch-ua-mobile", "?1")
	h.Set("baggage", baggage)
	h.Set("sentry-trace", trace)
	h.Set("user-agent", ua)
	h.Set("x-captcha-token", captchaToken)
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
	h.Set("cookie", "acw_tc=9581d31c17748587792257129e0deb0a34ec18f05b8a68459d00a474893677; slug=mobile-legends-bang-bang")
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

// fillInquiryRequest mengisi sebuah *fasthttp.Request dengan URI, method, header,
// dan body inquiry. Dipisah dari doInquiry supaya bisa dipanggil DI LUAR window
// burst (pre-build) — semua kerja berat (crypto/rand untuk UA/sentry-trace,
// json.Marshal body, ~20 fmt.Sprintf header) terjadi di sini, bukan di hot path.
func fillInquiryRequest(req *fasthttp.Request, order Order, captchaToken string) {
	req.SetRequestURI("https://gopay.co.id/games/v1/order/inquiry")
	req.Header.SetMethod("POST")
	setInquiryHeaders(req, captchaToken)
	req.SetBody(buildInquiryBody(order))
}

// prebuiltReqs menyimpan satu *fasthttp.Request siap-tembak per user untuk SALVO #1.
// Dibangun saat warmup (lihat prebuildInquiryRequests) sehingga di T=0 hot path
// hanya menjalankan fastClient.Do() murni — tidak ada alokasi/crypto di jalur kritis.
var (
	prebuiltReqs   = make(map[string]*fasthttp.Request)
	prebuiltReqsMu sync.Mutex
)

func orderKey(o Order) string { return o.UserID + "|" + o.ServerID }

// prebuildInquiryRequests membangun request salvo-1 untuk semua order memakai
// captcha final. Harus dipanggil SETELAH captcha terakhir di-refresh dan SEBELUM
// burst (idealnya di dalam window warmup yang masih punya budget waktu).
func prebuildInquiryRequests(orders []Order, captchaToken string) {
	prebuiltReqsMu.Lock()
	defer prebuiltReqsMu.Unlock()
	for _, o := range orders {
		req := fasthttp.AcquireRequest()
		fillInquiryRequest(req, o, captchaToken)
		prebuiltReqs[orderKey(o)] = req
	}
}

// doInquiryPrebuilt menembak memakai request yang sudah dibangun sebelumnya (salvo #1).
// Mengembalikan ok=false jika tidak ada prebuilt untuk order ini (fallback ke doInquiry).
func doInquiryPrebuilt(order Order) (InquiryResult, bool) {
	prebuiltReqsMu.Lock()
	req := prebuiltReqs[orderKey(order)]
	delete(prebuiltReqs, orderKey(order)) // sekali pakai; retry berikutnya rebuild fresh
	prebuiltReqsMu.Unlock()
	if req == nil {
		return InquiryResult{}, false
	}
	defer fasthttp.ReleaseRequest(req)

	resp := fasthttp.AcquireResponse()
	defer fasthttp.ReleaseResponse(resp)

	start := time.Now()
	err := fastClient.DoTimeout(req, resp, time.Duration(INQUIRY_TIMEOUT_MS)*time.Millisecond)
	rtt := time.Since(start).Seconds() * 1000
	if err != nil {
		return InquiryResult{Status: "retry", ErrMsg: err.Error(), RTTMs: rtt}, true
	}
	code := resp.StatusCode()
	status, orderID, errMsg := classifyInquiryResponse(code, resp.Body())
	return InquiryResult{Status: status, OrderID: orderID, ErrMsg: errMsg, HTTPCode: code, RTTMs: rtt}, true
}

func doInquiry(order Order, captchaToken string, attempt int) InquiryResult {
	req := fasthttp.AcquireRequest()
	defer fasthttp.ReleaseRequest(req)

	resp := fasthttp.AcquireResponse()
	defer fasthttp.ReleaseResponse(resp)

	fillInquiryRequest(req, order, captchaToken)

	start := time.Now()
	err := fastClient.DoTimeout(req, resp, time.Duration(INQUIRY_TIMEOUT_MS)*time.Millisecond)
	rtt := time.Since(start).Seconds() * 1000

	if err != nil {
		return InquiryResult{Status: "retry", ErrMsg: err.Error(), RTTMs: rtt}
	}

	code := resp.StatusCode()
	body := resp.Body()
	status, orderID, errMsg := classifyInquiryResponse(code, body)

	return InquiryResult{
		Status:   status,
		OrderID:  orderID,
		ErrMsg:   errMsg,
		HTTPCode: code,
		RTTMs:    rtt,
	}
}

// ===================== KALIBRASI JAM SERVER =====================

// probeServerDate menembak endpoint inquiry (tidak konsumsi voucher saat pre-war)
// lalu membaca header Date untuk mengukur jam server. Mengembalikan instant
// pembuatan response menurut jam VPS (start + rtt/2) dan detik epoch server.
func probeServerDate(order Order, captchaToken string) (serverGenLocalMs float64, dateSec int64, ok bool) {
	req := fasthttp.AcquireRequest()
	defer fasthttp.ReleaseRequest(req)
	resp := fasthttp.AcquireResponse()
	defer fasthttp.ReleaseResponse(resp)

	req.SetRequestURI("https://gopay.co.id/games/v1/order/inquiry")
	req.Header.SetMethod("POST")
	setInquiryHeaders(req, captchaToken)
	req.SetBody(buildInquiryBody(order))

	start := time.Now()
	err := fastClient.DoTimeout(req, resp, time.Duration(INQUIRY_TIMEOUT_MS)*time.Millisecond)
	rtt := time.Since(start)
	if err != nil {
		return 0, 0, false
	}

	dateBytes := resp.Header.Peek("Date")
	if len(dateBytes) == 0 {
		return 0, 0, false
	}
	t, perr := http.ParseTime(string(dateBytes))
	if perr != nil {
		return 0, 0, false
	}

	// Perkiraan momen server membuat response, diukur pakai jam VPS.
	genLocal := start.Add(rtt / 2)
	serverGenLocalMs = float64(genLocal.UnixNano()) / 1e6
	return serverGenLocalMs, t.Unix(), true
}

// computeClockOffset mendeteksi pergantian detik (rollover) pada header Date
// untuk menentukan batas detik server secara presisi sub-detik, lalu menetapkan
// serverClockOffsetMs. Header Date hanya presisi detik, jadi titik rollover
// (saat detik berganti) adalah satu-satunya cara mendapat akurasi milidetik.
func computeClockOffset(samples []clockSample) {
	if len(samples) < 2 {
		logf("[CLOCK] Sampel kurang (%d) — pakai jam VPS apa adanya.\n", len(samples))
		return
	}

	var offsets []float64
	for i := 1; i < len(samples); i++ {
		// Hanya pakai pergantian tepat +1 detik (rollover bersih, tanpa gap).
		if samples[i].sec != samples[i-1].sec+1 {
			continue
		}
		// Batas detik server berada di antara dua sampel; ambil titik tengah.
		boundaryLocal := (samples[i-1].localMs + samples[i].localMs) / 2.0
		// Pada momen itu jam server tepat = sec * 1000 ms.
		off := float64(samples[i].sec)*1000.0 - boundaryLocal
		offsets = append(offsets, off)
	}

	if len(offsets) == 0 {
		logf("[CLOCK] Tidak ada rollover detik terdeteksi — pakai jam VPS apa adanya.\n")
		return
	}

	sort.Float64s(offsets)
	med := offsets[len(offsets)/2]
	serverClockOffsetMs = med
	serverClockKnown = true

	hint := "jam VPS akurat"
	if med > 20 {
		hint = "jam VPS KETINGGALAN dari server → burst dimajukan otomatis"
	} else if med < -20 {
		hint = "jam VPS KECEPETAN dari server → burst dimundurkan otomatis"
	}
	logf("[CLOCK] Offset jam server vs VPS: %+.0fms (dari %d rollover) — %s\n", med, len(offsets), hint)
}

// ===================== ADAPTIVE INQUIRY =====================

// processInquiryResult mencatat statistik, menyusun log, dan menerapkan verdict
// (success/stop/skip/region/retry) untuk satu attempt. Mengembalikan done=true
// bila goroutine harus berhenti (tidak retry lagi). Dipakai bersama oleh salvo-1
// dan retry supaya tidak ada duplikasi logika.
//
// srv = fire + ow, ow = min(RTT_burst/2, RTT_med_warmup/2) — metrik utama timing war.
func processInquiryResult(order Order, k string, attempt int, fireTime time.Time, result InquiryResult) bool {
	fireOffset := 0.0
	if !warStartWall.IsZero() {
		fireOffset = float64(fireTime.Sub(warStartWall).Milliseconds())
	}

	// === Perhitungan LAMA (dipertahankan apa adanya: srv & ow) ===
	currentOneWay := result.RTTMs / 2.0
	oneWay := currentOneWay
	if warmupMedianRTT > 0 {
		warmOneWay := warmupMedianRTT / 2.0
		if warmOneWay < oneWay {
			oneWay = warmOneWay
		}
	}
	srvArrival := fireOffset + oneWay

	mu.Lock()
	attemptStats = append(attemptStats, AttemptStat{
		User:       k,
		Try:        attempt,
		RTTMs:      result.RTTMs,
		FireOffset: fireOffset,
		HTTPCode:   result.HTTPCode,
		Verdict:    result.Status,
	})
	attempts[k] = attempt

	tRel := float64(time.Since(warStartWall).Milliseconds())
	detailTag := fmt.Sprintf("[+%6.1fms][%s][try %d/%d][rtt %.0fms][srv%+.0fms][HTTP %d][fire%+.0fms][ow %.0fms]",
		tRel, k, attempt, MAX_RETRY_PER_USER+1, result.RTTMs, srvArrival, result.HTTPCode, fireOffset, oneWay)

	shortErr := truncateRunes(result.ErrMsg, 80)
	if shortErr == "" {
		shortErr = "(no message)"
	}

	var logLines []string
	done := false

	switch result.Status {
	case "success":
		successMap[k] = SuccessEntry{Order: order, OrderID: result.OrderID}
		logLines = append(logLines, fmt.Sprintf("%s ✅ OrderID: %s", detailTag, result.OrderID))
		done = true

	case "stop":
		stopGlobal = true
		logLines = append(logLines,
			fmt.Sprintf("%s ⚠️ stop: %s", detailTag, shortErr),
			"🛑 STOP GLOBAL: voucher habis terdeteksi. Batalkan semua retry.")
		done = true

	case "skip_user":
		skipUsers[k] = true
		logLines = append(logLines,
			fmt.Sprintf("%s ⚠️ skip_user: %s", detailTag, shortErr),
			fmt.Sprintf("[%s] ⏭️ SKIP USER: sudah pernah claim, tidak retry.", k))
		done = true

	case "user_invalid":
		// User ID salah (Error_Role_Null dll), jangan dianggap sebagai limit/redeem
		logLines = append(logLines,
			fmt.Sprintf("%s ❌ user_invalid: %s", detailTag, shortErr),
			fmt.Sprintf("[%s] ❌ USER ID SALAH: tidak retry.", k))
		done = true

	case "region_block":
		regionBlocked[k] = true
		logLines = append(logLines,
			fmt.Sprintf("%s ⚠️ region_block: %s", detailTag, shortErr),
			fmt.Sprintf("[%s] 🌐 USER ID DILUAR REGION: tidak retry.", k))
		done = true

	default:
		if attempt > MAX_RETRY_PER_USER {
			logLines = append(logLines, fmt.Sprintf("%s ⛔ max retry tercapai", detailTag))
			done = true
		} else {
			logLines = append(logLines, fmt.Sprintf("%s ⚠️ %s: %s → retry", detailTag, result.Status, shortErr))
		}
	}
	mu.Unlock()

	for _, ln := range logLines {
		logf("%s\n", ln)
	}
	return done
}

func runAdaptiveInquiry(orders []Order, captchaToken string) []SuccessEntry {
	var wg sync.WaitGroup
	key := func(o Order) string { return o.UserID + "|" + o.ServerID }

	// Broadcast-release barrier: semua goroutine salvo-1 di-spawn LEBIH DULU, lalu
	// blok di <-startCh. Saat semua siap, close(startCh) melepas kelimanya pada
	// instant yang sama — meniadakan jitter "goroutine #1 nembak sebelum #5 lahir".
	startCh := make(chan struct{})
	var ready sync.WaitGroup
	ready.Add(len(orders))

	for _, ord := range orders {
		wg.Add(1)
		go func(order Order) {
			defer wg.Done()
			k := key(order)

			for attempt := 1; attempt <= MAX_RETRY_PER_USER+1; attempt++ {
				mu.Lock()
				skip := stopGlobal || successMap[k] != (SuccessEntry{}) || skipUsers[k] || regionBlocked[k] || totalInquiries >= MAX_TOTAL_INQUIRIES
				if !skip {
					totalInquiries++
				}
				mu.Unlock()

				if skip {
					if attempt == 1 {
						ready.Done() // tetap lepaskan barrier walau goroutine ini batal
					}
					return
				}

				var (
					result   InquiryResult
					fireTime time.Time
				)
				if attempt == 1 {
					// Salvo #1: signal siap, tunggu barrier, lalu tembak prebuilt
					// request. Hot path murni Do() — tanpa alokasi/crypto/build.
					ready.Done()
					<-startCh
					fireTime = time.Now()
					if r, ok := doInquiryPrebuilt(order); ok {
						result = r
					} else {
						result = doInquiry(order, captchaToken, attempt)
					}
				} else {
					// Retry: jalan secepatnya tanpa barrier, build request fresh.
					fireTime = time.Now()
					result = doInquiry(order, captchaToken, attempt)
				}

				if processInquiryResult(order, k, attempt, fireTime, result) {
					return
				}

				time.Sleep(3 * time.Millisecond) // refire sangat cepat (PHP style)
			}
		}(ord)
	}

	// Lepas barrier: tunggu kelima goroutine selesai build & siap, lalu close.
	ready.Wait()
	logf("🔫 RELEASE barrier: %d user lepas bersamaan @ [%s]\n", len(orders), formatMicrotimeNow())
	close(startCh)

	wg.Wait()

	var results []SuccessEntry
	for _, v := range successMap {
		results = append(results, v)
	}
	return results
}

// ===================== PAYMENT (FASTHTTP) =====================

func runParallelPayment(successes []SuccessEntry) int {
	if len(successes) == 0 {
		return 0
	}

	var wg sync.WaitGroup
	successCount := 0
	var muPay sync.Mutex

	for _, entry := range successes {
		wg.Add(1)
		go func(e SuccessEntry) {
			defer wg.Done()
			k := e.Order.UserID + "|" + e.Order.ServerID
			orderID := e.OrderID
			// Fix: pakai SHA256 seperti di PHP
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
			setInquiryHeaders(req, "") // base headers
			req.Header.Set("x-timestamp", fmt.Sprintf("%d", time.Now().UnixMilli()))
			req.Header.Set("x-request-reference", ref)
			req.Header.Set("x-request-id", ref)
			req.Header.Set("idempotency-key", ref)
			req.Header.Del("x-captcha-token")
			req.SetBody(payBody)

			if err := fastClient.DoTimeout(req, resp, time.Duration(PAYMENT_TIMEOUT_MS)*time.Millisecond); err != nil {
				logf("[%s] Payment error: %v\n", k, err)
				return
			}
			if resp.StatusCode() != 200 && resp.StatusCode() != 201 {
				payErr := truncateRunes(extractApiErrorMessage(resp.Body()), 120)
				if payErr != "" {
					logf("[%s] Payment HTTP %d - %s\n", k, resp.StatusCode(), payErr)
				} else {
					logf("[%s] Payment HTTP %d\n", k, resp.StatusCode())
				}
				return
			}

			var payRes map[string]interface{}
			json.Unmarshal(resp.Body(), &payRes)
			txnID, _ := payRes["data"].(string)
			if txnID == "" {
				return
			}

			txnData := getTransactionUntilReady(txnID)
			if txnData != nil {
				payURL := ""
				if ap, ok := txnData["actionPayment"].(map[string]interface{}); ok {
					if pd, ok := ap["paymentDirect"].(string); ok {
						payURL = pd
					} else if dr, ok := ap["deeplinkRedirect"].(string); ok {
						payURL = dr
					}
				}
				appendToFile("transaksi_url.txt", fmt.Sprintf("%s|%s\n", k, "https://gopay.co.id/games/payment/"+txnID))
				appendToFile("deeplinks.txt", payURL+"\n")
				appendToFile("order_ids.txt", fmt.Sprintf("%s|%s|%s\n", k, orderID, payURL))

				logf("[%s] ✅ SUCCESS | Pay URL tersedia\n", k)
				muPay.Lock()
				successCount++
				muPay.Unlock()
			}
		}(entry)
	}
	wg.Wait()
	return successCount
}

func getTransactionUntilReady(txnID string) map[string]interface{} {
	delays := []time.Duration{90, 120, 160, 220, 300, 420, 560, 750, 950}

	for _, d := range delays {
		time.Sleep(d * time.Millisecond)

		req := fasthttp.AcquireRequest()
		resp := fasthttp.AcquireResponse()
		defer fasthttp.ReleaseRequest(req)
		defer fasthttp.ReleaseResponse(resp)

		req.SetRequestURI("https://gopay.co.id/games/v1/transaction/" + txnID)
		req.Header.SetMethod("GET")
		setInquiryHeaders(req, "")

		if err := fastClient.DoTimeout(req, resp, 3500*time.Millisecond); err != nil {
			continue
		}

		var data map[string]interface{}
		if json.Unmarshal(resp.Body(), &data) == nil {
			if ap, ok := data["actionPayment"].(map[string]interface{}); ok {
				if _, ok := ap["paymentDirect"]; ok || ap["deeplinkRedirect"] != nil {
					return data
				}
			}
		}
	}
	return nil
}

// ===================== TIMING & WARMUP =====================

func waitUntilPreWar(secondsBefore int) {
	target := readTargetTime()
	testTs := target.Add(-time.Duration(secondsBefore) * time.Second)

	if diff := time.Until(testTs); diff > 0 {
		logf("Menunggu fase tes koneksi (T-%ds sebelum war)...\n", secondsBefore)
		time.Sleep(diff)
	} else {
		logf("[PRE-WAR] Sudah lewat T-%ds window, langsung jalankan pre-war test sekarang...\n", secondsBefore)
	}
}

func preWarConnectionTest(sample Order, captcha string, times int) {
	logf("Pre-war connection test + kalibrasi jam server (%d kali)...\n", times)
	// Tembakan back-to-back (~150-250ms/req) membentang >1 detik sehingga pasti
	// melewati 1-2 pergantian detik server → cukup untuk deteksi rollover.
	samples := make([]clockSample, 0, times)
	for i := 0; i < times; i++ {
		if localMs, sec, ok := probeServerDate(sample, captcha); ok {
			samples = append(samples, clockSample{localMs: localMs, sec: sec})
		}
	}
	computeClockOffset(samples)
	logf("Pre-war test selesai\n")
}

// doFineTuneProbes: 8x inquiry @ 1 req/detik dari H-12s sampai H-5s.
func doFineTuneProbes(sample Order, captcha string) {
	logf("[FINE-TUNE] Probe RTT %dx @ 1 req/detik (H-12s → H-5s)...\n", FINE_TUNE_PROBE_COUNT)
	fineTuneRTTs = make([]float64, 0, FINE_TUNE_PROBE_COUNT)
	for i := 1; i <= FINE_TUNE_PROBE_COUNT; i++ {
		if i > 1 {
			time.Sleep(FINE_TUNE_PROBE_INTERVAL)
		}
		result := doInquiry(sample, captcha, 0)
		fineTuneRTTs = append(fineTuneRTTs, result.RTTMs)
		logf("  fine-tune #%d | rtt %.0fms | HTTP %d | %s\n", i, result.RTTMs, result.HTTPCode, result.Status)
	}
}

func doWarmupProbes(sample Order, captcha string, maxMs int) {
	logf("[WARM-UP] T-%.1fs re-warm TLS pool (%d paralel)...\n", float64(MINI_PROBE2_LEAD_MS)/1000, MINI_PROBE2_PARALLEL)

	var wg sync.WaitGroup
	var muWarm sync.Mutex
	var rtts []float64

	for i := 0; i < MINI_PROBE2_PARALLEL; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			start := time.Now()
			doInquiry(sample, captcha, 0)
			rtt := float64(time.Since(start).Milliseconds())

			muWarm.Lock()
			rtts = append(rtts, rtt)
			muWarm.Unlock()
		}()
	}
	wg.Wait()

	if len(rtts) > 0 {
		sort.Float64s(rtts)
		warmupMedianRTT = rtts[len(rtts)/2]
		warmupMinRTT = rtts[0]
		logf("[WARM-UP] RTT min=%.0fms med=%.0fms (min/2→ow_est, hanya dari H-1.5s %d paralel — bukan fine-tune)\n",
			warmupMinRTT, warmupMedianRTT, len(rtts))
	} else {
		logf("[WARM-UP] Selesai (RTT tidak tersedia)\n")
	}
}

func waitForExactBurstTime(fallbackLeadMs int, sample Order, captcha string, beforeBurst func(int)) {
	fineTuneRTTs = nil
	target := readTargetTime()

	// Koreksi ke JAM SERVER: target dari waktu.txt adalah wall-clock server.
	if serverClockKnown {
		target = target.Add(-time.Duration(serverClockOffsetMs) * time.Millisecond)
		logf("[CLOCK] Target burst dikoreksi %+.0fms ke jam server.\n", -serverClockOffsetMs)
	}

	warStartWall = target // T=0 — detik go-live server (HH:MM:00 dari waktu.txt)
	targetSrv := readTargetSrvMs()

	logf("⏰ Target burst (T=0): %s | target_srv: +%.0fms | lead.txt fallback: %dms\n",
		target.Format("15:04:05.000"), targetSrv, fallbackLeadMs)

	// Warm-up T-1.5s: RTT_min → lead dinamis; jika spike > rata-rata fine-tune, pakai tune_avg.
	warmupAt := target.Add(-time.Duration(MINI_PROBE2_LEAD_MS) * time.Millisecond)

	if d := time.Until(warmupAt); d > 15*time.Second {
		time.Sleep(d - time.Duration(FINE_TUNE_START_BEFORE_MS)*time.Millisecond)
		logf("Masuk fase fine tuning (H-12s → H-5s, %dx @ 1/detik)...\n", FINE_TUNE_PROBE_COUNT)
		doFineTuneProbes(sample, captcha)
	} else if d > 6*time.Second {
		time.Sleep(d - 6*time.Second)
		logf("Masuk fase fine tuning...\n")
	}

	spinWaitUntil(warmupAt)

	if beforeBurst != nil {
		budget := MINI_PROBE2_LEAD_MS - 200
		if budget >= 150 {
			beforeBurst(budget)
		}
	}

	leadMs := resolveLeadMs(fallbackLeadMs, targetSrv)
	execTarget := leadToExecTarget(target, leadMs)

	if execTarget.Before(time.Now()) {
		logf("[LEAD] ⚠️ Dynamic lead %dms membuat exec di masa lalu → fallback lead.txt %dms\n",
			leadMs, fallbackLeadMs)
		leadMs = fallbackLeadMs
		execTarget = leadToExecTarget(target, leadMs)
	}

	leadRTT, _ := effectiveLeadRTT()
	owEst := leadRTT / 2.0
	if owEst > 0 {
		estSrv := float64(leadMs) + owEst
		logf("⏰ Lead: %dms | Exec: %s | estimasi srv: %+.0fms\n",
			leadMs, execTarget.Format("15:04:05.000"), estSrv)
	} else {
		logf("⏰ Lead: %dms | Exec: %s\n", leadMs, execTarget.Format("15:04:05.000"))
	}

	spinWaitUntil(execTarget)
	logf("🚀 BURST START! [%s] MULAI FULL FLOW!\n\n", formatMicrotimeNow())
}

// printTimingSummary - Analisis timing untuk kalibrasi lead.txt
func printTimingSummary() {
	if len(attemptStats) == 0 {
		return
	}

	logf("\n📈 ========== TIMING SUMMARY (untuk kalibrasi lead) ==========\n")

	// Group by try number
	salvos := make(map[int][]AttemptStat)
	for _, s := range attemptStats {
		salvos[s.Try] = append(salvos[s.Try], s)
	}

	for try := 1; try <= MAX_RETRY_PER_USER+1; try++ {
		list, ok := salvos[try]
		if !ok {
			continue
		}

		var rtts []float64
		var offsets []float64
		stopCount := 0

		for _, s := range list {
			rtts = append(rtts, s.RTTMs)
			offsets = append(offsets, s.FireOffset)
			if s.Verdict == "stop" {
				stopCount++
			}
		}

		// Simple min/med/max
		minRTT, medRTT, maxRTT := minMedMax(rtts)
		minOff, medOff, maxOff := minMedMax(offsets)

		logf("   Salvo #%d (n=%d): RTT[min=%.0f med=%.0f max=%.0f] | FireOffset[min=%+.0f med=%+.0f max=%+.0f] | STOP=%d\n",
			try, len(list), minRTT, medRTT, maxRTT, minOff, medOff, maxOff, stopCount)
	}

	// First STOP timing (paling penting untuk kalibrasi)
	firstStop := 0.0
	for _, s := range attemptStats {
		if s.Verdict == "stop" {
			firstStop = s.FireOffset
			break
		}
	}

	if firstStop != 0 {
		logf("\n   → First OUT-OF-STOCK terdeteksi pada FireOffset ≈ %+.0fms\n", firstStop)
		logf("   → Saran: turunkan target_srv di target_srv.txt atau lead lebih awal (fire ≈ %dms)\n", int(firstStop))
	} else {
		logf("\n   → Tidak ada 'out of stock' → kemungkinan masih ada stok atau timing terlalu telat\n")
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
	}
	mw := io.MultiWriter(os.Stdout, logFile)
	logger = log.New(mw, "", log.LstdFlags)
}

func logf(format string, v ...interface{}) {
	logger.Printf(format, v...)
}

// ===================== MAIN =====================

func main() {
	initLogging()
	initFastHTTPClient()

	logf("=== GOPAY MLBB WDP WAR (GOLANG + FASTHTTP) ===\n")
	logf("MAX_USERS=%d | MAX_RETRY=%d | WARMUP_PARALLEL=%d | DYNAMIC_LEAD target_srv=+%.0fms\n\n",
		MAX_USERS, MAX_RETRY_PER_USER, MINI_PROBE2_PARALLEL, readTargetSrvMs())

	orders := loadOrders()
	if len(orders) == 0 {
		log.Fatal("Tidak ada order valid")
	}

	captcha := getFreshCaptcha(false)
	fallbackLeadMs := readLeadMs()
	logf("⚡ Lead fallback (lead.txt): %dms\n\n", fallbackLeadMs)

	waitUntilPreWar(60)
	preWarConnectionTest(orders[0], captcha, PREWAR_TEST_COUNT)
	captcha = getFreshCaptcha(true)
	logf("Captcha diperbarui\n\n")

	waitForExactBurstTime(fallbackLeadMs, orders[0], captcha, func(budgetMs int) {
		doWarmupProbes(orders[0], captcha, budgetMs)
		prebuildInquiryRequests(orders, captcha)
		logf("[PREBUILD] %d request salvo-1 siap (hot path = Do() murni)\n", len(orders))
	})

	logf("🔥 SALVO #1: tembak %d user paralel @ [%s]\n", len(orders), formatMicrotimeNow())
	inquirySuccess := runAdaptiveInquiry(orders, captcha)

	logf("\n📊 Inquiry selesai: %d/%d sukses\n", len(inquirySuccess), len(orders))

	printTimingSummary()

	success := runParallelPayment(inquirySuccess)

	logf("\n🏁 FULL FLOW SELESAI! Berhasil: %d / %d\n", success, len(orders))
	logf("Lihat: deeplinks.txt, order_ids.txt, transaksi_url.txt, loghasil.txt\n")
}
