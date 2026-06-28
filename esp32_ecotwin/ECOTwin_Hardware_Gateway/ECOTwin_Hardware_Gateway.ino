/*
  ECOTwin ESP32 hardware gateway

  Wiring to Arduino Uno:
  - Uno A4 / SoftwareSerial TX -> 1k/2k voltage divider -> ESP32 GPIO16 / RX2
  - Uno D5 / SoftwareSerial RX <- direct wire <- ESP32 GPIO17 / TX2
  - Uno GND                    -> ESP32 GND

  Serial Monitor:
  - Baud: 115200
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <WebServer.h>

const char WIFI_SSID[] = "ECOTwin-LAN";
const char WIFI_PASSWORD[] = "ecotwin123";

// Must match the XAMPP computer IP while it is connected to ECOTwin-LAN.
const char INGEST_URL[] = "http://192.168.4.2/Capstone/admin/api/hardware_ingest.php";
const char INGEST_TOKEN[] = "ecotwin-hardware-2026";

const int UNO_RX_PIN = 16;
const int UNO_TX_PIN = 17;
const unsigned long STATUS_INTERVAL_MS = 5000;
const size_t MAX_UNO_LINE_LENGTH = 3500;

WebServer server(80);
String incomingLine;
unsigned long uploadedCount = 0;
unsigned long ignoredCount = 0;
unsigned long lastStatusMs = 0;
int lastPostCode = 0;
String lastPostResponse = "none";
String lastUnoLinePreview = "none";

String jsonEscape(const String &value) {
  String out;
  for (size_t i = 0; i < value.length(); i++) {
    char c = value[i];
    if (c == '"' || c == '\\') out += '\\';
    if (c == '\n' || c == '\r') continue;
    out += c;
  }
  return out;
}

void startAccessPoint() {
  WiFi.mode(WIFI_AP);
  bool ok = WiFi.softAP(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("ESP32 AP start: ");
  Serial.println(ok ? "ok" : "failed");
  Serial.print("ESP32 AP IP: ");
  Serial.println(WiFi.softAPIP());
}

bool postToEcotwin(const String &payload) {
  HTTPClient http;
  http.setTimeout(6000);
  http.begin(INGEST_URL);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-ECOTWIN-TOKEN", INGEST_TOKEN);

  lastPostCode = http.POST(payload);
  lastPostResponse = http.getString();
  http.end();

  Serial.print("POST ");
  Serial.print(lastPostCode);
  Serial.print(" ");
  Serial.println(lastPostResponse);

  return lastPostCode >= 200 && lastPostCode < 300;
}

bool looksLikeSensorPayload(const String &line) {
  return line.startsWith("{") &&
         line.endsWith("}") &&
         line.indexOf("\"hardware\"") >= 0 &&
         line.indexOf("\"readings\"") >= 0;
}

void handleUnoLine(String line) {
  line.trim();
  if (line.length() == 0) return;

  lastUnoLinePreview = line.substring(0, min((int)line.length(), 180));

  if (!looksLikeSensorPayload(line)) {
    ignoredCount++;
    Serial.print("Ignored Uno line: ");
    Serial.println(lastUnoLinePreview);
    return;
  }

  Serial.print("UNO JSON received, length=");
  Serial.println(line.length());

  if (postToEcotwin(line)) {
    uploadedCount++;
  } else {
    Serial.println("Upload failed. Check INGEST_URL, firewall, Apache, MySQL, and API token.");
  }
}

void readUnoSerial() {
  while (Serial2.available()) {
    char c = (char)Serial2.read();
    if (c == '\n') {
      handleUnoLine(incomingLine);
      incomingLine = "";
    } else if (c != '\r') {
      incomingLine += c;
      if (incomingLine.length() > MAX_UNO_LINE_LENGTH) {
        Serial.println("UNO line too long; clearing buffer.");
        incomingLine = "";
      }
    }
  }
}

void sendUnoCommand(const String &command) {
  Serial2.println(command);
  Serial.print("Sent Uno command: ");
  Serial.println(command);
}

void handleCommandApi() {
  String command = server.arg("cmd");
  command.trim();
  command.toUpperCase();

  if (command != "RELAY:ON" &&
      command != "RELAY:OFF" &&
      command != "STEPPER:CW" &&
      command != "STEPPER:CCW" &&
      command != "STEPPER:STOP" &&
      command != "PING") {
    server.send(400, "application/json", "{\"ok\":false,\"error\":\"Unsupported command\"}");
    return;
  }

  sendUnoCommand(command);
  server.send(200, "application/json", "{\"ok\":true}");
}

String testPayload() {
  return "{\"greenhouse\":\"A\",\"hardware\":["
         "{\"label\":\"ESP32 Module\",\"type\":\"gateway\",\"status\":\"online\",\"model\":\"ESP32\",\"firmware_version\":\"esp32-gateway-2.0\"},"
         "{\"label\":\"Arduino Uno\",\"type\":\"controller\",\"status\":\"online\",\"model\":\"Arduino Uno\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"DHT22 Temperature-Humidity Sensor\",\"type\":\"sensor\",\"status\":\"online\",\"model\":\"DHT22\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"DS18B20 Waterproof Temperature Sensor\",\"type\":\"sensor\",\"status\":\"offline\",\"model\":\"DS18B20\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"TDS/EC Module\",\"type\":\"sensor\",\"status\":\"online\",\"model\":\"EC/TDS\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"pH Sensor Kit\",\"type\":\"sensor\",\"status\":\"online\",\"model\":\"Analog pH\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"LDR Light Sensor\",\"type\":\"sensor\",\"status\":\"online\",\"model\":\"LDR\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"Water Level Sensor\",\"type\":\"sensor\",\"status\":\"online\",\"model\":\"Analog Water Level\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"nRF Wireless Module\",\"type\":\"wireless\",\"status\":\"offline\",\"model\":\"nRF24L01\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"Relay Module\",\"type\":\"relay\",\"status\":\"online\",\"model\":\"5V Relay\",\"firmware_version\":\"uno-bridge-2.0\"},"
         "{\"label\":\"28BYJ-48 Stepper Motor\",\"type\":\"actuator\",\"status\":\"online\",\"model\":\"28BYJ-48 + ULN2003\",\"firmware_version\":\"uno-bridge-2.0\"}"
         "],\"readings\":{\"temperature\":25.5,\"humidity\":60,\"light\":500,\"water_level\":300}}";
}

void handleTestUpload() {
  bool ok = postToEcotwin(testPayload());
  if (ok) uploadedCount++;
  server.send(ok ? 200 : 500, "application/json", ok ? "{\"ok\":true}" : "{\"ok\":false}");
}

void handleStatus() {
  String json = "{";
  json += "\"ap_ip\":\"" + WiFi.softAPIP().toString() + "\",";
  json += "\"clients\":" + String(WiFi.softAPgetStationNum()) + ",";
  json += "\"uploaded_count\":" + String(uploadedCount) + ",";
  json += "\"ignored_count\":" + String(ignoredCount) + ",";
  json += "\"last_post_code\":" + String(lastPostCode) + ",";
  json += "\"last_post_response\":\"" + jsonEscape(lastPostResponse) + "\",";
  json += "\"last_uno_line_preview\":\"" + jsonEscape(lastUnoLinePreview) + "\"";
  json += "}";
  server.send(200, "application/json", json);
}

void handleRoot() {
  server.send(200, "text/plain",
              "ECOTwin Hardware Gateway\n"
              "GET /api/status\n"
              "GET /api/test-upload\n"
              "GET /api/command?cmd=PING\n"
              "Commands: RELAY:ON, RELAY:OFF, STEPPER:CW, STEPPER:CCW, STEPPER:STOP, PING\n");
}

void setupRoutes() {
  server.on("/", HTTP_GET, handleRoot);
  server.on("/api/status", HTTP_GET, handleStatus);
  server.on("/api/test-upload", HTTP_GET, handleTestUpload);
  server.on("/api/command", HTTP_GET, handleCommandApi);
  server.on("/api/command", HTTP_POST, handleCommandApi);
  server.begin();
}

void printPeriodicStatus() {
  if (millis() - lastStatusMs < STATUS_INTERVAL_MS) return;
  lastStatusMs = millis();

  Serial.print("Status: clients=");
  Serial.print(WiFi.softAPgetStationNum());
  Serial.print(", uploaded=");
  Serial.print(uploadedCount);
  Serial.print(", ignored=");
  Serial.print(ignoredCount);
  Serial.print(", lastPost=");
  Serial.println(lastPostCode);
}

void setup() {
  Serial.begin(115200);
  Serial2.begin(9600, SERIAL_8N1, UNO_RX_PIN, UNO_TX_PIN);
  delay(500);

  Serial.println();
  Serial.println("ECOTwin ESP32 hardware gateway started.");
  startAccessPoint();
  setupRoutes();
  Serial.println("Open http://192.168.4.1/api/status for gateway diagnostics.");
}

void loop() {
  server.handleClient();
  readUnoSerial();
  printPeriodicStatus();
}
