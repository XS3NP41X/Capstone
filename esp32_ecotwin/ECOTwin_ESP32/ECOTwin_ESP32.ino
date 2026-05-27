#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>

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
String apSsid, apPass, portalUser, portalPass;
String staSsid, staPass, ingestUrl, ingestKey;
String cloudStatus = "Not configured";
String activityLog[12];
int activityIndex = 0;
unsigned long bootMs = 0;
unsigned long lastReadingUpdate = 0;
unsigned long lastDbSync = 0;
unsigned long lastStaReconnect = 0;
unsigned long lastCloudPull = 0;

const char INDEX_HTML[] PROGMEM = R"HTML(
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ECOTwin ESP32</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:#f8f9fa;color:#1a1a1a}.top{position:sticky;top:0;background:white;border-bottom:1px solid #e5e7eb;box-shadow:0 1px 3px #0001;z-index:5}.bar{min-height:64px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;max-width:1180px;margin:0 auto;padding:12px 18px}.brand{font-size:20px;font-weight:700;display:flex;align-items:center;gap:10px}.brand:before{content:"";width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,#0d9488,#10b981)}.status{font-size:13px;color:#5a5a5a;text-align:right}.nav{display:flex;gap:8px;overflow:auto;max-width:1180px;margin:0 auto;padding:0 18px 12px}.nav button,.btn{border:0;background:#f3f4f6;color:#374151;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;white-space:nowrap}.nav button.active,.primary{background:#e0f2f1!important;color:#0d9488!important}.wrap{max-width:1180px;margin:auto;padding:20px 18px 42px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px}.span4{grid-column:span 4}.metric{font-size:28px;font-weight:800;margin:8px 0}.label{color:#5a5a5a;font-size:13px;font-weight:800;text-transform:uppercase}.sub{color:#5a5a5a;font-size:14px}.houses{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.house{border-top:4px solid #0d9488}.house:nth-child(2){border-top-color:#059669}.house h3{margin:0 0 4px}.readings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0}.reading{background:#f8faf9;border:1px solid #f3f4f6;border-radius:8px;padding:12px}.reading strong{display:block;font-size:21px}.controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.toggle{border:1px solid #e5e7eb;background:white;border-radius:8px;padding:11px 8px;font-weight:800;cursor:pointer}.toggle.on{background:#e0f2f1;border-color:#0d9488;color:#0d9488}.log{display:grid;gap:8px}.log div{padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:white}.form,.bridge{max-width:440px;margin:7vh auto;background:white;border:1px solid #e5e7eb;border-radius:12px;padding:28px;box-shadow:0 10px 30px #0001}.form:before,.bridge:before{content:"";display:block;width:74px;height:74px;margin:0 auto 16px;border-radius:16px;background:linear-gradient(135deg,#0d9488,#10b981)}.form h1,.bridge h1{text-align:center;font-size:21px;margin:0 0 8px}.form .sub,.bridge .sub{text-align:center}.field{display:grid;gap:6px;margin:13px 0}.field label{font-size:14px;font-weight:700}.field input{height:44px;border:1px solid #d1d5db;border-radius:8px;padding:0 12px;font:inherit}.error{color:#b91c1c;min-height:20px}.bridge-actions{display:flex;gap:10px;justify-content:center;margin-top:16px;flex-wrap:wrap}.hidden{display:none!important}@media(max-width:800px){.grid,.houses,.readings,.controls{grid-template-columns:1fr}.span4{grid-column:auto}.bar{grid-template-columns:1fr}.status{text-align:left}}
</style></head><body>
<section id="bridge" class="bridge">
<h1>EcoTwin Connection Gateway</h1><p class="sub" id="bridgeMessage">Checking cloud website access...</p>
<div class="bridge-actions">
<button class="btn primary" id="openCloudBtn" onclick="openCloudSite()" disabled>Open Cloud Website</button>
<button class="btn" onclick="showLocalPortal()">Use Local ESP32 Portal</button>
</div>
</section>
<section id="login" class="form">
<h1>EcoTwin: Dual-Greenhouse Research Framework</h1><p class="sub">ESP32 local greenhouse portal</p>
<div class="field"><label>Email Address</label><input id="user" autocomplete="username" value="admin"></div>
<div class="field"><label>Password</label><input id="pass" type="password" autocomplete="current-password" value="admin123"></div>
<button class="btn primary" onclick="login()">Sign In</button><p id="loginError" class="error"></p><p class="sub">ESP32 local login: admin / admin123</p>
</section>
<main id="app" class="hidden">
<div class="top"><div class="bar"><div class="brand">EcoTwin</div><div class="status" id="net">ESP32 local portal</div></div>
<div class="nav"><button class="active" onclick="tab('dashboard',this)">Dashboard</button><button onclick="tab('greenhouses',this)">Greenhouses</button><button onclick="tab('reports',this)">Reports</button><button onclick="tab('settings',this)">Settings</button><button onclick="logout()">Logout</button></div></div>
<div class="wrap"><section id="dashboard" class="view grid"></section><section id="greenhouses" class="view houses hidden"></section>
<section id="reports" class="view hidden"><div class="panel"><div class="label">Activity Report</div><h2>ESP32 Events</h2><div id="log" class="log"></div></div></section>
<section id="settings" class="view hidden"><div class="panel"><div class="label">Portal Settings</div><h2>Wi-Fi and Cloud Sync</h2>
<div class="field"><label>ESP32 Wi-Fi SSID</label><input id="ssid"></div><div class="field"><label>ESP32 Wi-Fi Password</label><input id="wifiPass"></div>
<div class="field"><label>Login Username</label><input id="setUser"></div><div class="field"><label>Login Password</label><input id="setPass" type="password"></div>
<div class="field"><label>Internet Router Wi-Fi SSID</label><input id="staSsid"></div><div class="field"><label>Internet Router Wi-Fi Password</label><input id="staPass" type="password"></div>
<div class="field"><label>Cloud Ingest API URL</label><input id="ingestUrl"></div><div class="field"><label>Cloud API Key</label><input id="ingestKey"></div>
<button class="btn primary" onclick="saveSettings()">Save Settings</button><p class="sub">Restart ESP32 after changing Wi-Fi or cloud settings.</p></div></section></div></main>
<script>
let state=null,loggedIn=false,cloudSite='';function $(id){return document.getElementById(id)}function rememberLogin(){loggedIn=true;try{localStorage.ecotwinToken='local';sessionStorage.ecotwinToken='local'}catch(e){}}function authed(){try{return loggedIn||localStorage.ecotwinToken==='local'||sessionStorage.ecotwinToken==='local'}catch(e){return loggedIn}}function showApp(){$('bridge').classList.add('hidden');$('login').classList.toggle('hidden',authed());$('app').classList.toggle('hidden',!authed());if(authed())refresh()}function showLocalPortal(){$('bridge').classList.add('hidden');$('login').classList.remove('hidden');$('app').classList.add('hidden');$('bridgeMessage').textContent='Cloud unreachable. Using local ESP32 portal.'}function siteFromIngest(url){let marker='/esp32_api/';let idx=url.indexOf(marker);return idx>0?url.substring(0,idx):url}async function tryCloudRedirect(){try{let r=await fetch('/api/state');state=await r.json();cloudSite=siteFromIngest(state.ingestUrl||'');if(!cloudSite){showLocalPortal();return}let healthUrl=cloudSite+'/esp32_api/health.php?api_key='+encodeURIComponent(state.ingestKey||'');let health=await fetch(healthUrl,{method:'GET'});if(!health.ok)throw new Error('health');let data=await health.json();if(data.success){$('bridgeMessage').textContent='Cloud website detected. Opening '+cloudSite;$('openCloudBtn').disabled=false;setTimeout(()=>window.location.href=cloudSite,900);return}showLocalPortal()}catch(e){showLocalPortal()}}function openCloudSite(){if(cloudSite)window.location.href=cloudSite}async function login(){try{let r=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user:$('user').value.trim(),pass:$('pass').value})});let j=await r.json();if(j.ok){rememberLogin();showApp()}else $('loginError').textContent='Invalid login. Use admin / admin123.'}catch(e){$('loginError').textContent='Open http://192.168.4.1 directly.'}}function logout(){loggedIn=false;try{localStorage.removeItem('ecotwinToken');sessionStorage.removeItem('ecotwinToken')}catch(e){}showApp()}function tab(id,btn){document.querySelectorAll('.view').forEach(v=>v.classList.add('hidden'));$(id).classList.remove('hidden');document.querySelectorAll('.nav button').forEach(b=>b.classList.remove('active'));btn.classList.add('active')}async function refresh(){let r=await fetch('/api/state');state=await r.json();render()}function houseCard(h,i){return `<div class="panel house"><h3>Greenhouse ${i+1}</h3><p class="sub">${i===0?'Research Chamber':'Control Chamber'}</p><div class="readings"><div class="reading"><span>Temperature</span><strong>${h.temp.toFixed(1)} C</strong></div><div class="reading"><span>Humidity</span><strong>${h.humidity.toFixed(0)}%</strong></div><div class="reading"><span>Light</span><strong>${h.light} lx</strong></div><div class="reading"><span>Soil</span><strong>${h.soil}%</strong></div></div><div class="controls"><button class="toggle ${h.pump?'on':''}" onclick="control(${i},'pump',${h.pump?0:1})">Pump ${h.pump?'ON':'OFF'}</button><button class="toggle ${h.fan?'on':''}" onclick="control(${i},'fan',${h.fan?0:1})">Fan ${h.fan?'ON':'OFF'}</button><button class="toggle ${h.growLight?'on':''}" onclick="control(${i},'light',${h.growLight?0:1})">Light ${h.growLight?'ON':'OFF'}</button></div></div>`}function render(){if(!state)return;let online=state.houses.length*4,active=state.houses.reduce((n,h)=>n+h.pump+h.fan+h.growLight,0);$('net').textContent=`${state.ssid} | Cloud: ${state.dbStatus} | ${state.uptime}`;$('dashboard').innerHTML=`<div class="panel"><div class="label">System</div><div class="metric">Online</div><p class="sub">ESP32 portal active</p></div><div class="panel"><div class="label">Sensors</div><div class="metric">${online}</div><p class="sub">Local readings</p></div><div class="panel"><div class="label">Cloud</div><div class="metric">${state.dbStatus}</div><p class="sub">${cloudSite||'ecotwin.page.gd'}</p></div><div class="panel"><div class="label">Clients</div><div class="metric">${state.clients}</div><p class="sub">Connected to ESP32 Wi-Fi</p></div><div class="span4 houses">${state.houses.map(houseCard).join('')}</div>`;$('greenhouses').innerHTML=state.houses.map(houseCard).join('');$('log').innerHTML=state.log.map(x=>`<div>${x}</div>`).join('')||'<p class="sub">No events yet.</p>';$('ssid').value=state.ssid;$('wifiPass').value=state.wifiPass;$('setUser').value=state.user;$('setPass').value='';$('staSsid').value=state.staSsid;$('staPass').value='';$('ingestUrl').value=state.ingestUrl;$('ingestKey').value=state.ingestKey}async function control(gh,target,value){await fetch('/api/control',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({gh,target,value})});refresh()}async function saveSettings(){await fetch('/api/settings',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({ssid:$('ssid').value,wifiPass:$('wifiPass').value,user:$('setUser').value,pass:$('setPass').value,staSsid:$('staSsid').value,staPass:$('staPass').value,ingestUrl:$('ingestUrl').value,ingestKey:$('ingestKey').value})});refresh();alert('Settings saved. Restart ESP32.')}setInterval(()=>{if(authed())refresh()},3000);document.addEventListener('keydown',e=>{if(!authed()&&e.key==='Enter')login()});tryCloudRedirect();
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
  apSsid = prefs.getString("ssid", "ECOTwin-ESP32");
  apPass = prefs.getString("wifiPass", "ecotwin123");
  portalUser = prefs.getString("user", "admin");
  portalPass = prefs.getString("pass", "admin123");
  staSsid = prefs.getString("staSsid", "");
  staPass = prefs.getString("staPass", "");
  ingestUrl = prefs.getString("ingestUrl", "https://ecotwin.page.gd/esp32_api/ingest.php");
  ingestKey = prefs.getString("ingestKey", "ecotwin-esp32-key");
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
  json += "\"staSsid\":\"" + escapeJson(staSsid) + "\",";
  json += "\"ingestUrl\":\"" + escapeJson(ingestUrl) + "\",";
  json += "\"ingestKey\":\"" + escapeJson(ingestKey) + "\",";
  json += "\"dbStatus\":\"" + escapeJson(cloudStatus) + "\",";
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
  bool userMatches = submittedUser == configuredUser || submittedUser == "admin" || submittedUser == "admin@spamast.edu";
  bool ok = userMatches && server.arg("pass") == portalPass;
  if (ok) addLog("User logged in");
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
  if (server.hasArg("user") && server.arg("user").length() > 0) {
    portalUser = server.arg("user");
    prefs.putString("user", portalUser);
  }
  if (server.arg("pass").length() > 0) {
    portalPass = server.arg("pass");
    prefs.putString("pass", portalPass);
  }
  if (server.hasArg("staSsid")) {
    staSsid = server.arg("staSsid");
    prefs.putString("staSsid", staSsid);
  }
  if (server.arg("staPass").length() > 0) {
    staPass = server.arg("staPass");
    prefs.putString("staPass", staPass);
  }
  if (server.hasArg("ingestUrl")) {
    ingestUrl = server.arg("ingestUrl");
    prefs.putString("ingestUrl", ingestUrl);
  }
  if (server.hasArg("ingestKey")) {
    ingestKey = server.arg("ingestKey");
    prefs.putString("ingestKey", ingestKey);
  }
  addLog("Portal settings saved");
  server.send(200, "application/json", "{\"ok\":true}");
}

void connectStationWifi() {
  if (staSsid.length() == 0) {
    cloudStatus = "No Wi-Fi";
    return;
  }
  WiFi.begin(staSsid.c_str(), staPass.c_str());
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 8000) delay(250);
  if (WiFi.status() == WL_CONNECTED) {
    cloudStatus = ingestUrl.length() > 0 ? "Ready" : "No API";
    addLog("Connected to internet Wi-Fi");
  } else {
    cloudStatus = "Wi-Fi failed";
    addLog("Internet Wi-Fi connection failed");
  }
}

void keepStationWifiAlive() {
  if (staSsid.length() == 0 || WiFi.status() == WL_CONNECTED) return;
  if (millis() - lastStaReconnect < 30000) return;
  lastStaReconnect = millis();
  cloudStatus = "Reconnecting";
  WiFi.disconnect();
  WiFi.begin(staSsid.c_str(), staPass.c_str());
}

String pullUrl() {
  int slash = ingestUrl.lastIndexOf('/');
  if (slash < 0) return "";
  String base = ingestUrl.substring(0, slash + 1);
  return base + "pull.php?api_key=" + ingestKey;
}

String extractControlValue(const String &json, const char *ghCode, const char *key) {
  String scope = String("\"") + ghCode + "\":{";
  int scopePos = json.indexOf(scope);
  if (scopePos < 0) return "";
  int keyPos = json.indexOf(String("\"") + key + "\":\"", scopePos);
  if (keyPos < 0) return "";
  int valueStart = keyPos + String(key).length() + 4;
  int valueEnd = json.indexOf('"', valueStart);
  if (valueEnd < 0) return "";
  return json.substring(valueStart, valueEnd);
}

void applyCloudControl(int index, const char *ghCode) {
  HTTPClient http;
  WiFiClient plainClient;
  WiFiClientSecure secureClient;
  String url = pullUrl();
  if (url.length() == 0) return;

  if (url.startsWith("https://")) {
    secureClient.setInsecure();
    http.begin(secureClient, url);
  } else {
    http.begin(plainClient, url);
  }

  http.addHeader("X-ECOTWIN-KEY", ingestKey);
  int status = http.GET();
  if (status >= 200 && status < 300) {
    String payload = http.getString();
    String pump = extractControlValue(payload, ghCode, "pump");
    String fan = extractControlValue(payload, ghCode, "fan");
    String light = extractControlValue(payload, ghCode, "light");

    if (pump == "on" || pump == "off") gh[index].pump = pump == "on";
    if (fan == "on" || fan == "off") gh[index].fan = fan == "on";
    if (light == "on" || light == "off") gh[index].growLight = light == "on";
    applyRelays();
    cloudStatus = "Read/Write";
  } else {
    cloudStatus = "Pull HTTP " + String(status);
  }
  http.end();
}

void pullCloudState() {
  if (ingestUrl.length() == 0 || WiFi.status() != WL_CONNECTED) return;
  if (millis() - lastCloudPull < 10000) return;
  lastCloudPull = millis();
  applyCloudControl(0, "A");
  applyCloudControl(1, "B");
}

void syncOneGreenhouse(int index, const char *code) {
  HTTPClient http;
  WiFiClient plainClient;
  WiFiClientSecure secureClient;
  if (ingestUrl.startsWith("https://")) {
    secureClient.setInsecure();
    http.begin(secureClient, ingestUrl);
  } else {
    http.begin(plainClient, ingestUrl);
  }
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-ECOTWIN-KEY", ingestKey);
  String body = "{\"api_key\":\"";
  body += escapeJson(ingestKey);
  body += "\",\"greenhouse\":\"";
  body += String(code);
  body += "\",\"values\":{";
  body += "\"temperature\":" + String(gh[index].temp, 1) + ",";
  body += "\"humidity\":" + String(gh[index].humidity, 0) + ",";
  body += "\"light\":" + String(gh[index].light) + ",";
  body += "\"soil\":" + String(gh[index].soil);
  body += "}}";
  int status = http.POST(body);
  http.end();
  if (status >= 200 && status < 300) {
    cloudStatus = "Synced";
    addLog("Greenhouse " + String(code) + " synced to cloud");
  } else {
    cloudStatus = "HTTP " + String(status);
    addLog("Cloud sync failed for greenhouse " + String(code) + " HTTP " + String(status));
  }
}

void syncReadingsToDatabase() {
  if (ingestUrl.length() == 0) {
    cloudStatus = "No API";
    return;
  }
  if (WiFi.status() != WL_CONNECTED) {
    cloudStatus = "Offline";
    return;
  }
  if (millis() - lastDbSync < 15000) return;
  lastDbSync = millis();
  syncOneGreenhouse(0, "A");
  syncOneGreenhouse(1, "B");
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
  WiFi.mode(WIFI_AP_STA);
  WiFi.softAP(apSsid.c_str(), apPass.c_str());
  connectStationWifi();
  dnsServer.start(DNS_PORT, "*", WiFi.softAPIP());
  setupRoutes();
  server.begin();
  addLog("ECOTwin portal started at 192.168.4.1");
}

void loop() {
  dnsServer.processNextRequest();
  server.handleClient();
  updateReadings();
  keepStationWifiAlive();
  pullCloudState();
  syncReadingsToDatabase();
}
