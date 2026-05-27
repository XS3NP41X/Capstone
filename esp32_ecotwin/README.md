# ECOTwin ESP32 Cloud Firmware

Open and upload:

```text
esp32_ecotwin/ECOTwin_ESP32/ECOTwin_ESP32.ino
```

Default ESP32 Wi-Fi:

```text
SSID: ECOTwin-ESP32
Password: ecotwin123
```

Default portal:

```text
http://192.168.4.1
```

Default login:

```text
admin / admin123
```

Default cloud ingest:

```text
https://ecotwin.page.gd/esp32_api/ingest.php
```

Default API key:

```text
ecotwin-esp32-key
```

The ESP32 now works as a hybrid gateway:

```text
1. User connects to ESP32 Wi-Fi
2. ESP32 page tries to open https://ecotwin.page.gd
3. If cloud is reachable, browser is redirected there
4. If cloud is not reachable, the local ESP32 portal is used
```

The ESP32 still sends readings to your InfinityFree MySQL database when
internet Wi-Fi is configured in Settings.
