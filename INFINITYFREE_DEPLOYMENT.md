# ECOTwin InfinityFree + ESP32 Deployment

Domain:

```text
https://ecotwin.page.gd
```

ESP32 cloud ingest URL:

```text
https://ecotwin.page.gd/esp32_api/ingest.php
```

ESP32 API key:

```text
ecotwin-esp32-key
```

## Architecture

```text
Users
  -> connect to ESP32 Wi-Fi
  -> open http://192.168.4.1

ESP32
  -> reads sensors
  -> controls relays
  -> connects to internet Wi-Fi in the background
  -> sends readings to ecotwin.page.gd

InfinityFree
  -> hosts PHP API and optional full ECOTwin website
  -> stores readings in MySQL
  -> phpMyAdmin views the MySQL database
```

## Files To Upload To InfinityFree

Upload your project files into InfinityFree `htdocs`.

Minimum cloud API files:

```text
admin/db.php
config/cloud_database.php
esp32_api/config.php
esp32_api/ingest.php
esp32_api/health.php
```

For the full cloud website, upload the whole project contents into `htdocs`.

## Cloud Database Config

1. Copy:

```text
config/cloud_database.example.php
```

2. Rename the copy to:

```text
config/cloud_database.php
```

3. Fill in your InfinityFree MySQL values:

```php
defined('DB_HOST') || define('DB_HOST', 'sqlXXX.infinityfree.com');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', 'if0_XXXXXXXX_ecotwin');
defined('DB_USER') || define('DB_USER', 'if0_XXXXXXXX');
defined('DB_PASS') || define('DB_PASS', 'YOUR_INFINITYFREE_DATABASE_PASSWORD');
```

Use the exact values from InfinityFree Control Panel > MySQL Databases.

## Database Import

In InfinityFree phpMyAdmin:

1. Select your database.
2. Open Import.
3. Import your full ECOTwin SQL dump.
4. Confirm these tables exist:

```text
greenhouses
sensors
sensor_readings
```

If these tables are missing, ESP32 sync cannot save readings.

## Test The Cloud API

After uploading, open:

```text
https://ecotwin.page.gd/esp32_api/health.php?api_key=ecotwin-esp32-key
```

Expected result:

```json
{"success":true}
```

It should also show that `greenhouses`, `sensors`, and `sensor_readings` exist.

## ESP32 Upload

Open this file in Arduino IDE:

```text
esp32_ecotwin/ECOTwin_ESP32/ECOTwin_ESP32.ino
```

Board:

```text
ESP32 Dev Module
```

Required libraries are included with the ESP32 Arduino board package:

```text
WiFi
WebServer
DNSServer
Preferences
HTTPClient
WiFiClientSecure
```

## ESP32 Portal Setup

After uploading:

1. Connect your phone/laptop to:

```text
SSID: ECOTwin-ESP32
Password: ecotwin123
```

2. Open:

```text
http://192.168.4.1
```

3. Login:

```text
admin / admin123
```

4. Open Settings and set:

```text
Internet Router Wi-Fi SSID: company internet Wi-Fi
Internet Router Wi-Fi Password: company Wi-Fi password
Cloud Ingest API URL: https://ecotwin.page.gd/esp32_api/ingest.php
Cloud API Key: ecotwin-esp32-key
```

5. Save settings.
6. Restart the ESP32.

## Expected Flow

Users connect to the ESP32 Wi-Fi and use:

```text
http://192.168.4.1
```

The ESP32 uses the router Wi-Fi in the background to save readings to:

```text
https://ecotwin.page.gd/esp32_api/ingest.php
```

Then you can view the saved readings in InfinityFree phpMyAdmin.
