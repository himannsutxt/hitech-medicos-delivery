<?php
declare(strict_types=1);
use HMD\{Db, Perm, Settings, Ui, Auth};

$u = Auth::user();
$canTrail = Perm::has($u, 'gps.history');
$wh = Db::one('SELECT lat, lng, name, radius_m FROM hm_warehouse WHERE status=1 AND lat IS NOT NULL ORDER BY warehouse_id LIMIT 1');
$center = $wh ? [(float)$wh['lat'], (float)$wh['lng']] : [28.4595, 77.0266];
$showOff = Settings::get('live_show_offline') === '1';
$statuses = ['' => 'All statuses', 'travelling' => 'Delivering', 'at_customer' => 'At customer', 'available' => 'Available', 'at_warehouse' => 'At store', 'gps_offline' => 'GPS offline', 'off_duty' => 'Off duty'];

$body = '<div class="live-grid">'
    . '<div class="eva-card live-side">'
    . '<div class="eva-filters" style="margin-bottom:8px">'
    . '<div style="flex:1 1 100%"><label>Search' . Ui::tip('Type a rider\'s name, code, mobile or current order number.') . '</label><input id="lv-q" placeholder="Name, code, mobile, order" style="width:100%"></div>'
    . '<div style="flex:1 1 60%"><label>Status' . Ui::tip('Show only riders in this state. GPS offline = no location update for more than the time set in Settings → GPS & tracking.') . '</label><select id="lv-s" style="width:100%">' . Ui::options($statuses, '') . '</select></div>'
    . '<div style="flex:1 1 35%"><label>Sort' . Ui::tip('Order of the rider list.') . '</label><select id="lv-o" style="width:100%"><option value="name">Name</option><option value="ago">Last update</option><option value="pending">Most pending</option><option value="battery">Lowest battery</option></select></div>'
    . ($showOff ? '<label style="display:flex;align-items:center;gap:6px;font-weight:600;font-size:12px;color:#6f7d95"><input type="checkbox" id="lv-off" checked> Show offline &amp; off-duty riders' . Ui::tip('Grey dots show where riders were last seen, with the time. Turn off in Settings → Maps.') . '</label>' : '')
    . '</div>'
    . '<div class="live-counts" id="lv-counts"></div>'
    . '<div id="lv-list" class="live-list" aria-live="polite"></div>'
    . '</div>'
    . '<div class="eva-card live-mapcard"><div class="eva-h"><h4><i class="fa-solid fa-location-crosshairs"></i> <span id="lv-title">All riders</span></h4>'
    . '<span><button class="eva-btn sm" id="lv-fit"' . Ui::t('Zoom the map to show all riders') . '><i class="fa-solid fa-expand"></i> Show all</button> '
    . '<button class="eva-btn sm" id="lv-stop" hidden' . Ui::t('Stop following this rider and show everyone') . '><i class="fa-solid fa-xmark"></i> Stop following</button></span></div>'
    . '<div id="lv-map" class="map live-map"></div>' . Ui::mapNote()
    . '<div class="muted small" style="margin-top:6px"><span id="lv-upd"></span> · Refreshes every 10 seconds' . Ui::tip('Locations come from the rider app while the rider is on duty. Riders are never tracked off duty.') . '</div></div>'
    . '</div>';

$css = '<style>.live-grid{display:grid;grid-template-columns:360px 1fr;gap:14px;align-items:start}.live-side{max-height:calc(100vh - 150px);display:flex;flex-direction:column}.live-list{overflow-y:auto;flex:1;margin:0 -6px}'
    . '.live-map{height:calc(100vh - 230px);min-height:420px}.lv-item{display:flex;gap:10px;padding:10px 8px;border-radius:10px;cursor:pointer;border:1px solid transparent}.lv-item:hover{background:#f6f8fd}.lv-item.on{background:#f0eafe;border-color:#d8ccfb}'
    . '.lv-dot{width:12px;height:12px;border-radius:50%;margin-top:4px;flex:none;box-shadow:0 0 0 3px #fff,0 0 0 4px #e4e9f2}.lv-name{font-weight:700}.lv-meta{color:#6f7d95;font-size:12px;line-height:1.5}.lv-place{font-size:12px;color:#33415e}'
    . '.lv-warn{font-size:12px;color:#b36b00;background:#fff3e0;border-radius:6px;padding:3px 6px;margin:3px 0}.lv-warn.bad{color:#c2304a;background:#fde8ec}.lv-warn a{font-weight:700}.live-counts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}.live-counts span{font-size:11px;font-weight:700;padding:3px 8px;border-radius:99px;background:#eef1f6;color:#5b6880}'
    . '@media (max-width:1000px){.live-grid{grid-template-columns:1fr}.live-side{max-height:none}.live-list{max-height:320px}.live-map{height:60vh;min-height:320px}}</style>';

$js = '<script nonce="' . HMD_NONCE . '">HMMap.ready(function(){
var map=HMMap.create("lv-map",{center:' . json_encode($center) . ',zoom:12}),esc=HMMap.esc,canTrail=' . ($canTrail ? 'true' : 'false') . ';
' . ($wh ? 'map.circle(' . $center[0] . ',' . $center[1] . ',' . (int)$wh['radius_m'] . ',{color:"#20b2c4"});map.marker(' . $center[0] . ',' . $center[1] . ',{color:"#20b2c4",size:6,title:' . json_encode($wh['name']) . '});' : '') . '
var marks={},riders=[],follow=null,trail=null,firstFit=true,places={};
var q=document.getElementById("lv-q"),fs=document.getElementById("lv-s"),fo=document.getElementById("lv-o"),off=document.getElementById("lv-off");
function popup(r){return "<b>"+esc(r.name)+"</b> <span style=\\"color:#6f7d95\\">"+esc(r.code)+"</span><br>"+esc(r.status)+(r.order?"<br>Order: <a href=\\"?r=order&id="+r.order_id+"\\">"+esc(r.order)+"</a> ("+esc(r.customer)+")":"")+"<br>Speed: "+r.speed+" km/h · KM today: "+r.km+"<br>Delivered "+r.done+" · Pending "+r.pending+"<br>Battery: "+(r.battery==null?"—":r.battery+"%")+"<br>Last update: "+esc(r.ago)+"<br><a href=\\"?r=rider&id="+r.id+"\\">Open rider page</a>";}
function visible(){var t=q.value.trim().toLowerCase(),s=fs.value;return riders.filter(function(r){if(s&&r.status_key!==s)return false;if(off&&!off.checked&&r.offline)return false;if(!t)return true;return (r.name+" "+r.code+" "+r.mobile+" "+(r.order||"")).toLowerCase().indexOf(t)>-1;}).sort(function(a,b){var o=fo.value;if(o==="ago")return (a.ago_s==null?1e12:a.ago_s)-(b.ago_s==null?1e12:b.ago_s);if(o==="pending")return b.pending-a.pending;if(o==="battery")return (a.battery==null?999:a.battery)-(b.battery==null?999:b.battery);return a.name.localeCompare(b.name);});}
function draw(){var list=visible(),ids={},pts=[];
list.forEach(function(r){if(r.lat==null)return;ids[r.id]=1;pts.push([r.lat,r.lng]);var size=follow===r.id?12:9,key=r.color+size,m=marks[r.id];
if(m&&m.key===key){m.h.update(r.lat,r.lng,popup(r),r.name+" · "+r.status);}else{if(m)m.h.remove();marks[r.id]={key:key,h:map.marker(r.lat,r.lng,{color:r.color,size:size,z:follow===r.id?99:(r.offline?1:5),title:r.name+" · "+r.status,popup:popup(r)})};}});
Object.keys(marks).forEach(function(k){if(!ids[k]){marks[k].h.remove();delete marks[k];}});
var pk=function(r){return r.lat==null?"":r.lat.toFixed(3)+","+r.lng.toFixed(3);};
var h="";list.forEach(function(r){var pl=places[r.id],place=(pl&&pl.k===pk(r)&&pl.n)?pl.n:(r.lat==null?"No location yet":(r.lat.toFixed(5)+", "+r.lng.toFixed(5)));
h+="<div class=\\"lv-item"+(follow===r.id?" on":"")+"\\" data-id=\\""+r.id+"\\" tabindex=\\"0\\" title=\\"Click to follow "+esc(r.name)+" on the map\\"><span class=\\"lv-dot\\" style=\\"background:"+r.color+"\\"></span><div style=\\"min-width:0;flex:1\\"><div class=\\"lv-name\\">"+esc(r.name)+" <span class=\\"lv-meta\\">"+esc(r.code)+"</span></div>"
+"<div class=\\"lv-place\\"><i class=\\"fa-solid fa-location-dot\\" style=\\"color:#9aa5b8\\"></i> "+esc(place)+"</div>"
+(r.issues&&r.issues.length?"<div class=\\"lv-warn "+(r.issues.some(function(x){return x.level==="bad";})?"bad":"")+"\\" title=\\""+esc(r.issues.map(function(x){return x.title;}).join(" • "))+"\\"><i class=\\"fa-solid fa-triangle-exclamation\\"></i> "+esc(r.issues[0].title)+(r.issues.length>1?" (+"+(r.issues.length-1)+" more)":"")+" <a href=\\"?r=rider&id="+r.id+"\\">Fix</a></div>":"")+"<div class=\\"lv-meta\\">"+esc(r.status)+(r.order?" · "+esc(r.order):"")+" · "+r.speed+" km/h · "+(r.battery==null?"—":r.battery+"%")+" · "+esc(r.ago)+"</div></div></div>";});
document.getElementById("lv-list").innerHTML=h||"<div class=\\"eva-empty\\">No riders match. Riders appear here after they log in to the app and start duty.</div>";
var c={};riders.forEach(function(r){c[r.status]=(c[r.status]||0)+1;});document.getElementById("lv-counts").innerHTML=Object.keys(c).map(function(k){return "<span>"+esc(k)+": "+c[k]+"</span>";}).join("");
if(firstFit&&pts.length&&!follow){map.fit(pts,15);firstFit=false;}
list.forEach(function(r){var k=pk(r);if(r.lat==null||(places[r.id]&&places[r.id].k===k))return;places[r.id]={k:k,n:null};HMMap.placeName(r.lat,r.lng).then(function(n){if(!n||!places[r.id]||places[r.id].k!==k)return;places[r.id].n=n;var el=document.querySelector(".lv-item[data-id=\\""+r.id+"\\"] .lv-place");if(el)el.innerHTML="<i class=\\"fa-solid fa-location-dot\\" style=\\"color:#9aa5b8\\"></i> "+esc(n);});});}
function load(start){var url="?r=live.json"+(off&&off.checked?"&all=1":"")+(follow?"&follow="+follow+(start?"&start=1":""):"");
fetch(url,{credentials:"same-origin"}).then(function(x){return x.json();}).then(function(d){if(!d.ok)return;riders=d.riders;
draw();
if(follow){var r=riders.find(function(x){return x.id===follow;});if(trail)trail.remove();trail=null;if(d.trail&&d.trail.length>1)trail=map.line(d.trail,{color:"#6b5cf0",weight:5});if(r&&r.lat!=null)map.view(r.lat,r.lng,start?16:null);
document.getElementById("lv-title").textContent=r?("Following "+r.name+(d.trail_minutes?" · last "+d.trail_minutes+" min":"")):"Rider";}
document.getElementById("lv-upd").textContent="Updated "+d.server_time;}).catch(function(){document.getElementById("lv-upd").textContent="Connection problem, retrying…";});}
document.getElementById("lv-list").addEventListener("click",function(e){var it=e.target.closest(".lv-item");if(!it)return;follow=+it.dataset.id;document.getElementById("lv-stop").hidden=false;load(true);if(window.innerWidth<1000)document.getElementById("lv-map").scrollIntoView({behavior:"smooth"});});
document.getElementById("lv-list").addEventListener("keydown",function(e){if(e.key==="Enter"&&e.target.classList.contains("lv-item"))e.target.click();});
document.getElementById("lv-stop").onclick=function(){follow=null;if(trail)trail.remove();trail=null;this.hidden=true;document.getElementById("lv-title").textContent="All riders";firstFit=true;draw();};
document.getElementById("lv-fit").onclick=function(){var p=visible().filter(function(r){return r.lat!=null;}).map(function(r){return [r.lat,r.lng];});map.fit(p,15);};
[q,fs,fo].forEach(function(el){el.addEventListener("input",draw);el.addEventListener("change",draw);});if(off)off.addEventListener("change",function(){load(false);});
load(false);setInterval(function(){load(false);},10000);});</script>';
Ui::page('Live Tracking', 'live', $css . $body . $js, 'Where every rider is right now. Click a rider to follow them.', '', Ui::mapHead());
