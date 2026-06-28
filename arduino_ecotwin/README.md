# ECOTwin Arduino/ESP32 Hardware Bridge

Use `ECOTwin_Uno_Bridge` on the Arduino Uno and `ECOTwin_Hardware_Gateway` on the ESP32.

## Arduino Uno pins

| Hardware | Uno Pin |
| --- | --- |
| DHT22 data | D2 |
| DS18B20 data | D3 |
| Relay input | D4 |
| ULN2003 IN1 | D6 |
| ULN2003 IN2 | D7 |
| ULN2003 IN3 | D8 |
| ULN2003 IN4 | D9 |
| nRF24L01 CE | D10 |
| nRF24L01 CSN | A3 |
| nRF24L01 MOSI | D11 |
| nRF24L01 MISO | D12 |
| nRF24L01 SCK | D13 |
| pH analog | A0 |
| TDS/EC analog | A1 |
| LDR analog | A2 |
| Water level analog | A5 |
| SoftwareSerial RX | D5 from ESP32 TX2 GPIO17 |
| SoftwareSerial TX | A4 to ESP32 RX2 GPIO16 through level shifting |
| GND | ESP32 GND |

This version does not use Uno `D0/D1` for ESP32 communication.

## ESP32 back-to-Arduino commands

For two-way control, wire:

```text
Arduino Uno A4/TX -> ESP32 GPIO16/RX2 through 1k + 2k voltage divider
ESP32 GPIO17/TX2  -> Arduino Uno D5/RX
Arduino GND       -> ESP32 GND
```

After connecting to `ECOTwin-LAN`, you can test commands in a browser:

```text
http://192.168.4.1/api/command?cmd=RELAY:ON
http://192.168.4.1/api/command?cmd=RELAY:OFF
http://192.168.4.1/api/command?cmd=STEPPER:CW
http://192.168.4.1/api/command?cmd=STEPPER:CCW
http://192.168.4.1/api/command?cmd=STEPPER:STOP
```

## ESP32 settings to change

Open `esp32_ecotwin/ECOTwin_Hardware_Gateway/ECOTwin_Hardware_Gateway.ino` and set:

```cpp
const char WIFI_SSID[] = "ECOTwin-LAN";
const char WIFI_PASSWORD[] = "ecotwin123";
const char INGEST_URL[] = "http://192.168.4.2/Capstone/admin/api/hardware_ingest.php";
```

`192.168.4.2` must be the IP address of the XAMPP computer while it is connected to the ESP32 Wi-Fi. The ESP32 creates the Wi-Fi access point using those SSID/password values.

## Required Arduino libraries

Install these in Arduino IDE Library Manager:

- `DHT sensor library` by Adafruit
- `OneWire`
- `DallasTemperature`
- `RF24` by TMRh20

## Dashboard behavior

Every 5 seconds the Uno sends sensor readings and hardware status to the ESP32. The ESP32 posts them to:

```text
/Capstone/admin/api/hardware_ingest.php
```

That API updates `hardware_components`, marks matching sensors online, and inserts live sensor readings for the ECOTwin dashboard.
