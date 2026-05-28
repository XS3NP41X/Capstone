#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <Preferences.h>

static const byte DNS_PORT = 53;
static const int RELAY_PUMP_1 = 25;
static const int RELAY_FAN_1 = 26;
static const int RELAY_LIGHT_1 = 27;
static const int RELAY_PUMP_2 = 14;
static const int RELAY_FAN_2 = 12;
static const int RELAY_LIGHT_2 = 13;

WebServer server(80);
DNSServer dnsServer;
Preferences prefs;

struct GreenhouseState {
  float temp;
  float humidity;
  int light;
  int soil;
  bool pump;
  bool fan;
  bool growLight;
};

GreenhouseState gh[2];
String apSsid, apPass, portalUser, portalPass, lanWebsiteUrl;
String activityLog[12];
int activityIndex = 0;
unsigned long bootMs = 0;
unsigned long lastReadingUpdate = 0;

const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ECOTwin LAN Gateway</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:#f7faf8;color:#17211d}.gateway,.form{max-width:460px;margin:7vh auto;background:#fff;border:1px solid #e3e8e5;border-radius:12px;padding:28px;box-shadow:0 12px 34px #0001}.gateway:before,.form:before{content:"";display:block;width:72px;height:72px;margin:0 auto 16px;border-radius:16px;background:linear-gradient(135deg,#0d9488,#22c55e)}h1{text-align:center;font-size:22px;margin:0 0 8px}.sub{text-align:center;color:#5d6b64;font-size:14px}.actions{display:grid;gap:10px;margin-top:18px}.btn{border:0;background:#eef2f0;color:#24312b;border-radius:8px;padding:12px 14px;font-weight:800;cursor:pointer;text-decoration:none;text-align:center}.primary{background:#0d9488;color:white}.field{display:grid;gap:6px;margin:13px 0}.field label{font-size:14px;font-weight:700}.field input{height:44px;border:1px solid #ccd6d0;border-radius:8px;padding:0 12px;font:inherit}.hidden{display:none!important}.top{position:sticky;top:0;background:#fff;border-bottom:1px solid #e3e8e5;z-index:5}.bar{min-height:64px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;max-width:1180px;margin:auto;padding:12px 18px}.brand{font-size:20px;font-weight:800}.status{font-size:13px;color:#5d6b64;text-align:right}.nav{display:flex;gap:8px;overflow:auto;max-width:1180px;margin:auto;padding:0 18px 12px}.nav button{border:0;background:#eef2f0;color:#24312b;border-radius:8px;padding:10px 14px;font-weight:800;white-space:nowrap}.nav button.active{background:#dff5ef;color:#0d9488}.wrap{max-width:1180px;margin:auto;padding:20px 18px 42px}.grid,.houses{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.panel{background:#fff;border:1px solid #e3e8e5;border-radius:10px;padding:18px}.metric{font-size:28px;font-weight:900;margin:8px 0}.label{color:#5d6b64;font-size:13px;font-weight:800;text-transform:uppercase}.readings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0}.reading{background:#f7faf8;border:1px solid #eef2f0;border-radius:8px;padding:12px}.reading strong{display:block;font-size:21px}.controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.toggle{border:1px solid #d6ded9;background:#fff;border-radius:8px;padding:11px 8px;font-weight:800}.toggle.on{background:#dff5ef;border-color:#0d9488;color:#0d9488}.log{display:grid;gap:8px}.log div{padding:10px;border:1px solid #e3e8e5;border-radius:8px;background:#fff}.error{color:#b91c1c;min-height:20px}@media(max-width:800px){.metrics,.grid,.houses,.readings,.controls{grid-template-columns:1fr}.bar{grid-template-columns:1fr}.status{text-align:left}}
</style></head><body>
<section id="gateway" class="gateway">
<h1>ECOTwin LAN Gateway</h1><p class="sub">Opening the local ECOTwin website through this ESP32 Wi-Fi.</p>
<div class="actions"><a class="btn primary" id="siteLink" href="#">Open ECOTwin Website</a><button class="btn" onclick="showLocalPortal()">ESP32 Device Portal</button></div>
<p class="sub" id="hint">Connect the XAMPP server computer to this ESP32 Wi-Fi.</p>
</section>
<section id="login" class="form hidden">
<h1>ESP32 Device Portal</h1><p class="sub">Local hardware diagnostics and relay test</p>
<div class="field"><label>Username</label><input id="user" autocomplete="username" value="admin"></div>
<div class="field"><label>Password</label><input id="pass" type="password" autocomplete="current-password" value="admin123"></div>
<button class="btn primary" onclick="login()">Sign In</button><p id="loginError" class="error"></p>
</section>
<main id="app" class="hidden"><div class="top"><div class="bar"><div class="brand">ECOTwin ESP32</div><div class="status" id="net">LAN gateway active</div></div>
<div class="nav"><button class="active" onclick="tab('dashboard',this)">Dashboard</button><button onclick="tab('greenhouses',this)">Greenhouses</button><button onclick="tab('settings',this)">Settings</button><button onclick="logout()">Logout</button></div></div>
<div class="wrap"><section id="dashboard" class="view"></section><section id="greenhouses" class="view houses hidden"></section>
<section id="settings" class="view hidden"><div class="panel"><div class="label">LAN Gateway Settings</div><h2>ESP32 Wi-Fi and Website URL</h2>
<div class="field"><label>ESP32 Wi-Fi SSID</label><input id="ssid"></div><div class="field"><label>ESP32 Wi-Fi Password</label><input id="wifiPass"></div>
<div class="field"><label>LAN Website URL</label><input id="lanUrl"></div><div class="field"><label>Portal Username</label><input id="setUser"></div><div class="field"><label>Portal Password</label><input id="setPass" type="password"></div>
<button class="btn primary" onclick="saveSettings()">Save Settings</button><p class="sub">Restart ESP32 after changing Wi-Fi settings.</p><div id="log" class="log"></div></div></section></div></main>
<script>
let state=null,loggedIn=false;function $(id){return document.getElementById(id)}function authed(){try{return loggedIn||localStorage.ecotwinToken==='lan'}catch(e){return loggedIn}}function rememberLogin(){loggedIn=true;try{localStorage.ecotwinToken='lan'}catch(e){}}function showLocalPortal(){$('gateway').classList.add('hidden');$('login').classList.toggle('hidden',authed());$('app').classList.toggle('hidden',!authed());if(authed())refresh()}async function init(){let r=await fetch('/api/state');state=await r.json();$('siteLink').href=state.lanUrl;$('hint').textContent='Website URL: '+state.lanUrl;setTimeout(()=>{location.href=state.lanUrl},1200)}async function login(){let r=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user:$('user').value.trim(),pass:$('pass').value})});let j=await r.json();if(j.ok){rememberLogin();showLocalPortal()}else $('loginError').textContent='Invalid login.'}function logout(){loggedIn=false;try{localStorage.removeItem('ecotwinToken')}catch(e){}showLocalPortal()}function tab(id,btn){document.querySelectorAll('.view').forEach(v=>v.classList.add('hidden'));$(id).classList.remove('hidden');document.querySelectorAll('.nav button').forEach(b=>b.classList.remove('active'));btn.classList.add('active')}async function refresh(){let r=await fetch('/api/state');state=await r.json();render()}function houseCard(h,i){return `<div class="panel"><h3>Greenhouse ${i+1}</h3><p class="sub">${i===0?'Treatment':'Control'} chamber</p><div class="readings"><div class="reading"><span>Temperature</span><strong>${h.temp.toFixed(1)} C</strong></div><div class="reading"><span>Humidity</span><strong>${h.humidity.toFixed(0)}%</strong></div><div class="reading"><span>Light</span><strong>${h.light} lx</strong></div><div class="reading"><span>Soil</span><strong>${h.soil}%</strong></div></div><div class="controls"><button class="toggle ${h.pump?'on':''}" onclick="control(${i},'pump',${h.pump?0:1})">Pump ${h.pump?'ON':'OFF'}</button><button class="toggle ${h.fan?'on':''}" onclick="control(${i},'fan',${h.fan?0:1})">Fan ${h.fan?'ON':'OFF'}</button><button class="toggle ${h.growLight?'on':''}" onclick="control(${i},'light',${h.growLight?0:1})">Light ${h.growLight?'ON':'OFF'}</button></div></div>`}function render(){if(!state)return;$('net').textContent=`${state.ssid} | ${state.clients} client(s) | ${state.uptime}`;$('dashboard').innerHTML=`<div class="metrics"><div class="panel"><div class="label">Mode</div><div class="metric">LAN</div><p class="sub">No internet required</p></div><div class="panel"><div class="label">Website</div><div class="metric">Local</div><p class="sub">${state.lanUrl}</p></div><div class="panel"><div class="label">Clients</div><div class="metric">${state.clients}</div><p class="sub">Connected to ESP32 Wi-Fi</p></div><div class="panel"><div class="label">Uptime</div><div class="metric">${state.uptime}</div><p class="sub">Gateway active</p></div></div><div class="houses" style="margin-top:16px">${state.houses.map(houseCard).join('')}</div>`;$('greenhouses').innerHTML=state.houses.map(houseCard).join('');$('ssid').value=state.ssid;$('wifiPass').value=state.wifiPass;$('lanUrl').value=state.lanUrl;$('setUser').value=state.user;$('setPass').value='';$('log').innerHTML=state.log.map(x=>`<div>${x}</div>`).join('')||'<p class="sub">No events yet.</p>'}async function control(gh,target,value){await fetch('/api/control',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({gh,target,value})});refresh()}async function saveSettings(){await fetch('/api/settings',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({ssid:$('ssid').value,wifiPass:$('wifiPass').value,lanUrl:$('lanUrl').value,user:$('setUser').value,pass:$('setPass').value})});refresh();alert('Settings saved. Restart ESP32 if Wi-Fi changed.')}setInterval(()=>{if(authed())refresh()},3000);init();
</script></body></html>
)HTML";

void addLog(const String &message) {
  activityLog[activityIndex] = String(millis() / 1000) + "s - " + message;
  activityIndex = (activityIndex + 1) % 12;
}

void setupPins() {
  int pins[] = {RELAY_PUMP_1, RELAY_FAN_1, RELAY_LIGHT_1, RELAY_PUMP_2, RELAY_FAN_2, RELAY_LIGHT_2};
  for (int i = 0; i < 6; i++) {
    pinMode(pins[i], OUTPUT);
    digitalWrite(pins[i], LOW);
  }
}

void applyRelays() {
  digitalWrite(RELAY_PUMP_1, gh[0].pump ? HIGH : LOW);
  digitalWrite(RELAY_FAN_1, gh[0].fan ? HIGH : LOW);
  digitalWrite(RELAY_LIGHT_1, gh[0].growLight ? HIGH : LOW);
  digitalWrite(RELAY_PUMP_2, gh[1].pump ? HIGH : LOW);
  digitalWrite(RELAY_FAN_2, gh[1].fan ? HIGH : LOW);
  digitalWrite(RELAY_LIGHT_2, gh[1].growLight ? HIGH : LOW);
}

void loadSettings() {
  prefs.begin("ecotwin", false);
  apSsid = prefs.getString("ssid", "ECOTwin-LAN");
  apPass = prefs.getString("wifiPass", "ecotwin123");
  portalUser = prefs.getString("user", "admin");
  portalPass = prefs.getString("pass", "admin123");
  lanWebsiteUrl = prefs.getString("lanUrl", "http://192.168.4.2/Capstone/");
  if (apPass.length() < 8) apPass = "ecotwin123";
}

void seedReadings() {
  gh[0] = {28.2, 68.0, 620, 54, false, true, false};
  gh[1] = {27.4, 64.0, 590, 57, false, false, false};
}

void updateReadings() {
  if (millis() - lastReadingUpdate < 2500) return;
  lastReadingUpdate = millis();
  for (int i = 0; i < 2; i++) {
    gh[i].temp = constrain(gh[i].temp + random(-3, 4) / 10.0, 20.0, 38.0);
    gh[i].humidity = constrain(gh[i].humidity + random(-2, 3), 35.0, 95.0);
    gh[i].light = constrain(gh[i].light + random(-25, 26), 120, 1200);
    gh[i].soil = constrain(gh[i].soil + random(-1, 2), 15, 90);
  }
}

String uptimeLabel() {
  unsigned long s = (millis() - bootMs) / 1000;
  return String(s / 3600) + "h " + String((s % 3600) / 60) + "m " + String(s % 60) + "s";
}

String jsonBool(bool value) { return value ? "true" : "false"; }

String escapeJson(const String &input) {
  String out;
  for (size_t i = 0; i < input.length(); i++) {
    char c = input[i];
    if (c == '"' || c == '\\') out += '\\';
    out += c;
  }
  return out;
}

void sendState() {
  updateReadings();
  String json = "{";
  json += "\"ssid\":\"" + escapeJson(apSsid) + "\",";
  json += "\"wifiPass\":\"" + escapeJson(apPass) + "\",";
  json += "\"user\":\"" + escapeJson(portalUser) + "\",";
  json += "\"lanUrl\":\"" + escapeJson(lanWebsiteUrl) + "\",";
  json += "\"uptime\":\"" + uptimeLabel() + "\",";
  json += "\"clients\":" + String(WiFi.softAPgetStationNum()) + ",";
  json += "\"houses\":[";
  for (int i = 0; i < 2; i++) {
    if (i) json += ",";
    json += "{\"temp\":" + String(gh[i].temp, 1) + ",\"humidity\":" + String(gh[i].humidity, 0) + ",\"light\":" + String(gh[i].light) + ",\"soil\":" + String(gh[i].soil) + ",\"pump\":" + jsonBool(gh[i].pump) + ",\"fan\":" + jsonBool(gh[i].fan) + ",\"growLight\":" + jsonBool(gh[i].growLight) + "}";
  }
  json += "],\"log\":[";
  bool first = true;
  for (int i = 0; i < 12; i++) {
    int idx = (activityIndex + i) % 12;
    if (activityLog[idx].length() == 0) continue;
    if (!first) json += ",";
    json += "\"" + escapeJson(activityLog[idx]) + "\"";
    first = false;
  }
  json += "]}";
  server.send(200, "application/json", json);
}

void handleLogin() {
  String submittedUser = server.arg("user");
  submittedUser.trim();
  submittedUser.toLowerCase();
  String configuredUser = portalUser;
  configuredUser.trim();
  configuredUser.toLowerCase();
  bool ok = (submittedUser == configuredUser || submittedUser == "admin") && server.arg("pass") == portalPass;
  if (ok) addLog("Device portal login");
  server.send(200, "application/json", ok ? "{\"ok\":true}" : "{\"ok\":false}");
}

void handleControl() {
  int house = server.arg("gh").toInt();
  String target = server.arg("target");
  bool value = server.arg("value") == "1";
  if (house < 0 || house > 1) {
    server.send(400, "application/json", "{\"ok\":false}");
    return;
  }
  if (target == "pump") gh[house].pump = value;
  else if (target == "fan") gh[house].fan = value;
  else if (target == "light") gh[house].growLight = value;
  else {
    server.send(400, "application/json", "{\"ok\":false}");
    return;
  }
  applyRelays();
  addLog("Greenhouse " + String(house + 1) + " " + target + " set " + (value ? "ON" : "OFF"));
  server.send(200, "application/json", "{\"ok\":true}");
}

void handleSettings() {
  if (server.hasArg("ssid") && server.arg("ssid").length() > 0) {
    apSsid = server.arg("ssid");
    prefs.putString("ssid", apSsid);
  }
  if (server.arg("wifiPass").length() >= 8) {
    apPass = server.arg("wifiPass");
    prefs.putString("wifiPass", apPass);
  }
  if (server.hasArg("lanUrl") && server.arg("lanUrl").length() > 0) {
    lanWebsiteUrl = server.arg("lanUrl");
    prefs.putString("lanUrl", lanWebsiteUrl);
  }
  if (server.hasArg("user") && server.arg("user").length() > 0) {
    portalUser = server.arg("user");
    prefs.putString("user", portalUser);
  }
  if (server.arg("pass").length() > 0) {
    portalPass = server.arg("pass");
    prefs.putString("pass", portalPass);
  }
  addLog("LAN gateway settings saved");
  server.send(200, "application/json", "{\"ok\":true}");
}

void serveIndex() { server.send_P(200, "text/html", INDEX_HTML); }

void setupRoutes() {
  server.on("/", HTTP_GET, serveIndex);
  server.on("/api/login", HTTP_POST, handleLogin);
  server.on("/api/state", HTTP_GET, sendState);
  server.on("/api/control", HTTP_POST, handleControl);
  server.on("/api/settings", HTTP_POST, handleSettings);
  server.onNotFound(serveIndex);
}

void setup() {
  Serial.begin(115200);
  bootMs = millis();
  randomSeed(esp_random());
  setupPins();
  loadSettings();
  seedReadings();
  WiFi.mode(WIFI_AP);
  WiFi.softAP(apSsid.c_str(), apPass.c_str());
  dnsServer.start(DNS_PORT, "*", WiFi.softAPIP());
  setupRoutes();
  server.begin();
  addLog("LAN gateway started at 192.168.4.1");
}

void loop() {
  dnsServer.processNextRequest();
  server.handleClient();
  updateReadings();
}
