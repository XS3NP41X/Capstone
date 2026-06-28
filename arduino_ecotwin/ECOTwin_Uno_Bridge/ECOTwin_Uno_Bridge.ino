/*
  ECOTwin Arduino Uno sensor bridge

  Wiring to ESP32:
  - Uno A4 / SoftwareSerial TX -> 1k/2k voltage divider -> ESP32 GPIO16 / RX2
  - Uno D5 / SoftwareSerial RX <- direct wire <- ESP32 GPIO17 / TX2
  - Uno GND                    -> ESP32 GND

  USB Serial Monitor:
  - Baud: 9600
  - Shows short debug messages only.

  Required libraries:
  - DHT sensor library by Adafruit
  - OneWire
  - DallasTemperature
  - RF24 by TMRh20
*/

#include <DHT.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <SPI.h>
#include <RF24.h>
#include <SoftwareSerial.h>

#define DHT_PIN 2
#define DHT_TYPE DHT22
#define DS18B20_PIN 3
#define RELAY_PIN 4

#define ESP32_RX_PIN 5
#define ESP32_TX_PIN A4

#define STEPPER_IN1_PIN 6
#define STEPPER_IN2_PIN 7
#define STEPPER_IN3_PIN 8
#define STEPPER_IN4_PIN 9

#define NRF_CE_PIN 10
#define NRF_CSN_PIN A3

#define PH_PIN A0
#define TDS_PIN A1
#define LDR_PIN A2
#define WATER_LEVEL_PIN A5

const char GREENHOUSE_CODE[] = "A";
const char FIRMWARE_VERSION[] = "uno-bridge-2.0";
const unsigned long SEND_INTERVAL_MS = 5000;

DHT dht(DHT_PIN, DHT_TYPE);
OneWire oneWire(DS18B20_PIN);
DallasTemperature ds18b20(&oneWire);
RF24 radio(NRF_CE_PIN, NRF_CSN_PIN);
SoftwareSerial esp32Serial(ESP32_RX_PIN, ESP32_TX_PIN);

bool nrfOnline = false;
bool relayOn = false;
unsigned long lastSendMs = 0;
unsigned long payloadCount = 0;
String commandLine;

const byte STEPPER_SEQUENCE[8][4] = {
  {1, 0, 0, 0},
  {1, 1, 0, 0},
  {0, 1, 0, 0},
  {0, 1, 1, 0},
  {0, 0, 1, 0},
  {0, 0, 1, 1},
  {0, 0, 0, 1},
  {1, 0, 0, 1}
};

float analogToPh(int raw) {
  float voltage = raw * (5.0 / 1023.0);
  return 7.0 + ((2.5 - voltage) / 0.18);
}

float analogToTds(int raw) {
  float voltage = raw * (5.0 / 1023.0);
  return (133.42 * voltage * voltage * voltage - 255.86 * voltage * voltage + 857.39 * voltage) * 0.5;
}

bool validFloat(float value) {
  return !isnan(value) && value > -100.0 && value < 2000.0;
}

void printHardwareItem(const char *label, const char *type, const char *status, const char *model, bool comma) {
  if (comma) esp32Serial.print(',');
  esp32Serial.print("{\"label\":\"");
  esp32Serial.print(label);
  esp32Serial.print("\",\"type\":\"");
  esp32Serial.print(type);
  esp32Serial.print("\",\"status\":\"");
  esp32Serial.print(status);
  esp32Serial.print("\",\"model\":\"");
  esp32Serial.print(model);
  esp32Serial.print("\",\"firmware_version\":\"");
  esp32Serial.print(FIRMWARE_VERSION);
  esp32Serial.print("\"}");
}

void addReadingPrefix(bool &firstReading) {
  if (!firstReading) esp32Serial.print(',');
  firstReading = false;
}

void sendPayload() {
  float airTemp = dht.readTemperature();
  float humidity = dht.readHumidity();

  ds18b20.requestTemperatures();
  float waterTemp = ds18b20.getTempCByIndex(0);

  int phRaw = analogRead(PH_PIN);
  int tdsRaw = analogRead(TDS_PIN);
  int ldrRaw = analogRead(LDR_PIN);
  int waterLevelRaw = analogRead(WATER_LEVEL_PIN);
  float ph = analogToPh(phRaw);
  float tds = analogToTds(tdsRaw);

  bool dhtOk = validFloat(airTemp) && validFloat(humidity);
  bool dsOk = waterTemp != DEVICE_DISCONNECTED_C && waterTemp > -55.0 && waterTemp < 125.0;
  bool phOk = phRaw > 3 && phRaw < 1020;
  bool tdsOk = tdsRaw > 3 && tdsRaw < 1020;
  bool ldrOk = ldrRaw > 3 && ldrRaw < 1020;
  bool waterLevelOk = waterLevelRaw > 3 && waterLevelRaw < 1020;

  esp32Serial.print("{\"greenhouse\":\"");
  esp32Serial.print(GREENHOUSE_CODE);
  esp32Serial.print("\",\"hardware\":[");
  printHardwareItem("Arduino Uno", "controller", "online", "Arduino Uno", false);
  printHardwareItem("ESP32 Module", "gateway", "online", "ESP32", true);
  printHardwareItem("DHT22 Temperature-Humidity Sensor", "sensor", dhtOk ? "online" : "offline", "DHT22", true);
  printHardwareItem("DS18B20 Waterproof Temperature Sensor", "sensor", dsOk ? "online" : "offline", "DS18B20", true);
  printHardwareItem("TDS/EC Module", "sensor", tdsOk ? "online" : "offline", "EC/TDS", true);
  printHardwareItem("pH Sensor Kit", "sensor", phOk ? "online" : "offline", "Analog pH", true);
  printHardwareItem("LDR Light Sensor", "sensor", ldrOk ? "online" : "offline", "LDR", true);
  printHardwareItem("Water Level Sensor", "sensor", waterLevelOk ? "online" : "offline", "Analog Water Level", true);
  printHardwareItem("nRF Wireless Module", "wireless", nrfOnline ? "online" : "offline", "nRF24L01", true);
  printHardwareItem("Relay Module", "relay", "online", "5V Relay", true);
  printHardwareItem("28BYJ-48 Stepper Motor", "actuator", "online", "28BYJ-48 + ULN2003", true);
  esp32Serial.print("],\"readings\":{");

  bool firstReading = true;
  if (dhtOk) {
    addReadingPrefix(firstReading);
    esp32Serial.print("\"temperature\":");
    esp32Serial.print(airTemp, 2);
    esp32Serial.print(",\"humidity\":");
    esp32Serial.print(humidity, 2);
  }
  if (dsOk) {
    addReadingPrefix(firstReading);
    esp32Serial.print("\"water_temp\":");
    esp32Serial.print(waterTemp, 2);
  }
  if (phOk) {
    addReadingPrefix(firstReading);
    esp32Serial.print("\"ph\":");
    esp32Serial.print(ph, 2);
  }
  if (tdsOk) {
    addReadingPrefix(firstReading);
    esp32Serial.print("\"ec\":");
    esp32Serial.print(tds, 2);
  }
  if (ldrOk) {
    addReadingPrefix(firstReading);
    esp32Serial.print("\"light\":");
    esp32Serial.print(ldrRaw);
  }
  if (waterLevelOk) {
    addReadingPrefix(firstReading);
    esp32Serial.print("\"water_level\":");
    esp32Serial.print(waterLevelRaw);
  }

  esp32Serial.println("}}");

  payloadCount++;
  Serial.print("Sent payload #");
  Serial.print(payloadCount);
  Serial.print(" to ESP32 on A4. DHT=");
  Serial.print(dhtOk ? "online" : "offline");
  Serial.print(", DS18B20=");
  Serial.print(dsOk ? "online" : "offline");
  Serial.print(", nRF=");
  Serial.print(nrfOnline ? "online" : "offline");
  Serial.print(", waterLevelRaw=");
  Serial.println(waterLevelRaw);
}

void setStepperPins(byte index) {
  digitalWrite(STEPPER_IN1_PIN, STEPPER_SEQUENCE[index][0]);
  digitalWrite(STEPPER_IN2_PIN, STEPPER_SEQUENCE[index][1]);
  digitalWrite(STEPPER_IN3_PIN, STEPPER_SEQUENCE[index][2]);
  digitalWrite(STEPPER_IN4_PIN, STEPPER_SEQUENCE[index][3]);
}

void releaseStepper() {
  digitalWrite(STEPPER_IN1_PIN, LOW);
  digitalWrite(STEPPER_IN2_PIN, LOW);
  digitalWrite(STEPPER_IN3_PIN, LOW);
  digitalWrite(STEPPER_IN4_PIN, LOW);
}

void moveStepper(int steps, int direction) {
  for (int i = 0; i < steps; i++) {
    byte index = direction > 0 ? i % 8 : 7 - (i % 8);
    setStepperPins(index);
    delay(2);
  }
  releaseStepper();
}

void sendAck(const char *message) {
  esp32Serial.print("{\"ack\":\"");
  esp32Serial.print(message);
  esp32Serial.println("\"}");
  Serial.print("Command done: ");
  Serial.println(message);
}

void handleCommand(String command) {
  command.trim();
  command.toUpperCase();
  if (command.length() == 0) return;

  Serial.print("Command from ESP32: ");
  Serial.println(command);

  if (command == "RELAY:ON") {
    relayOn = true;
    digitalWrite(RELAY_PIN, HIGH);
    sendAck("RELAY:ON");
  } else if (command == "RELAY:OFF") {
    relayOn = false;
    digitalWrite(RELAY_PIN, LOW);
    sendAck("RELAY:OFF");
  } else if (command == "STEPPER:CW") {
    moveStepper(512, 1);
    sendAck("STEPPER:CW");
  } else if (command == "STEPPER:CCW") {
    moveStepper(512, -1);
    sendAck("STEPPER:CCW");
  } else if (command == "STEPPER:STOP") {
    releaseStepper();
    sendAck("STEPPER:STOP");
  } else if (command == "PING") {
    sendAck("PONG");
  } else {
    sendAck("UNKNOWN_COMMAND");
  }
}

void readEsp32Commands() {
  while (esp32Serial.available()) {
    char c = (char)esp32Serial.read();
    if (c == '\n') {
      handleCommand(commandLine);
      commandLine = "";
    } else if (c != '\r') {
      commandLine += c;
      if (commandLine.length() > 80) commandLine = "";
    }
  }
}

void setupPins() {
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, LOW);

  pinMode(STEPPER_IN1_PIN, OUTPUT);
  pinMode(STEPPER_IN2_PIN, OUTPUT);
  pinMode(STEPPER_IN3_PIN, OUTPUT);
  pinMode(STEPPER_IN4_PIN, OUTPUT);
  releaseStepper();
}

void setup() {
  Serial.begin(9600);
  esp32Serial.begin(9600);
  setupPins();

  dht.begin();
  ds18b20.begin();

  nrfOnline = radio.begin();
  if (nrfOnline) {
    radio.setPALevel(RF24_PA_LOW);
    radio.stopListening();
  }

  Serial.println("ECOTwin Uno bridge started.");
  Serial.println("Full JSON is sent on A4 SoftwareSerial, not USB Serial.");
}

void loop() {
  readEsp32Commands();

  if (lastSendMs == 0 || millis() - lastSendMs >= SEND_INTERVAL_MS) {
    lastSendMs = millis();
    sendPayload();
  }
}
