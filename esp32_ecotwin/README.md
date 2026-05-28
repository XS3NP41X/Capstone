# ECOTwin ESP32 LAN Gateway

This firmware is for offline/LAN deployment. It does not use external hosting,
remote APIs, or internet Wi-Fi.

## What The ESP32 Does

```text
Users connect to ESP32 Wi-Fi
ESP32 opens a local gateway page
Gateway redirects users to the LAN website
Website runs on the XAMPP computer connected to ESP32 Wi-Fi
MySQL runs locally on that XAMPP computer
```

The ESP32 cannot run the full PHP/MySQL website by itself. It works as the
Wi-Fi access point and gateway to the local XAMPP website.

## Default ESP32 Wi-Fi

```text
SSID: ECOTwin-LAN
Password: ecotwin123
Gateway: http://192.168.4.1
```

## Default Website Redirect

```text
http://192.168.4.2/Capstone/
```

Use this setup:

```text
XAMPP computer connects to ECOTwin-LAN
XAMPP computer IP: 192.168.4.2
Visitors connect to ECOTwin-LAN
Visitors open: http://192.168.4.1
ESP32 redirects to: http://192.168.4.2/Capstone/
```

## XAMPP Setup

1. Put the project here:

```text
C:\xampp\htdocs\Capstone
```

2. Start Apache and MySQL.

3. Import the database as:

```text
ecotwin_db
```

4. Connect the XAMPP computer to `ECOTwin-LAN`.

5. Set the XAMPP computer Wi-Fi IPv4 manually:

```text
IP address: 192.168.4.2
Subnet mask: 255.255.255.0
Gateway: 192.168.4.1
```

6. Test from the XAMPP computer:

```text
http://localhost/Capstone/login.php
http://192.168.4.2/Capstone/
```

## ESP32 Upload

Open and upload:

```text
esp32_ecotwin/ECOTwin_ESP32/ECOTwin_ESP32.ino
```

Use Arduino IDE with:

```text
Board: ESP32 Dev Module
Libraries: WiFi, WebServer, DNSServer, Preferences
```

## Change Gateway URL

Connect to:

```text
http://192.168.4.1
```

Open the ESP32 Device Portal and set:

```text
LAN Website URL: http://192.168.4.2/Capstone/
```
