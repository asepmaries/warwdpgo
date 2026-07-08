# warwdpgo

Setup cepat untuk menjalankan `war.go` di Termux Android.

## Install di Termux

```bash
curl -fsSL https://raw.githubusercontent.com/asepmaries/warwdpgo/main/install.sh | bash
```

Setelah selesai:

```bash
cd ~/warwdpgo
bash input.sh
go run war.go
```

## File Input Lokal

File berikut sengaja tidak ikut diupload ke GitHub:

- `user_server_wdp.txt`
- `waktu.txt`
- `lead.txt`
- `target_srv.txt`
- `reload.txt`

Format `user_server_wdp.txt`:

```text
USER_ID|SERVER_ID
USER_ID|SERVER_ID
```

Format `waktu.txt`:

```text
11:02
```

atau:

```text
11:02:30
```

## Jalankan di Background Termux

```bash
pkg install tmux -y
tmux new -s war
cd ~/warwdpgo
go run war.go
```

Lepas session: `CTRL+B`, lalu `D`.

Masuk lagi:

```bash
tmux attach -t war
```
