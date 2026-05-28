# ECOTwin LAN Deployment

This deployment is offline/LAN only.

## Network Flow

```text
XAMPP computer runs PHP + MySQL
XAMPP computer connects to ESP32 Wi-Fi
Users connect to ESP32 Wi-Fi
ESP32 redirects users to the XAMPP website
```

Default ESP32 Wi-Fi:

```text
SSID: ECOTwin-LAN
Password: ecotwin123
Gateway page: http://192.168.4.1
Website URL: http://192.168.4.2/Capstone/
```

## XAMPP Computer

Put this project in:

```text
C:\xampp\htdocs\Capstone
```

Start:

```text
Apache
MySQL
```

Use local database:

```text
ecotwin_db
```

Set the XAMPP computer Wi-Fi adapter to:

```text
IP address: 192.168.4.2
Subnet mask: 255.255.255.0
Gateway: 192.168.4.1
```

Then test:

```text
http://192.168.4.2/Capstone/
```

## ESP32

Upload:

```text
esp32_ecotwin/ECOTwin_ESP32/ECOTwin_ESP32.ino
```

The ESP32 gateway opens:

```text
http://192.168.4.1
```

It redirects to:

```text
http://192.168.4.2/Capstone/
```

## Access Rule

Users can access the website only when they are on the ESP32 LAN, as long as the
XAMPP computer is connected only to `ECOTwin-LAN` and the website URL uses
`192.168.4.2`.
