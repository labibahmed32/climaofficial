// Cloudflare Worker — IPQS + AbuseIPDB Proxy
// Deploy at: https://dash.cloudflare.com → Workers & Pages → Create Worker

export default {
  async fetch(request) {
    const url = new URL(request.url);
    const key = url.searchParams.get('key') || '';
    const ip = url.searchParams.get('ip') || '';
    const email = url.searchParams.get('email') || '';
    const phone = url.searchParams.get('phone') || '';
    const abuseKey = url.searchParams.get('abusekey') || '';
    const abuseIp = url.searchParams.get('abuseip') || '';

    const headers = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, OPTIONS',
      'Content-Type': 'application/json'
    };

    if (request.method === 'OPTIONS') {
      return new Response('', { headers });
    }

    // Geo lookup — returns client IP + geo from Cloudflare (free, unlimited)
    const geo = url.searchParams.get('geo');
    if (geo) {
      const cf = request.cf || {};
      return new Response(JSON.stringify({
        ip: request.headers.get('CF-Connecting-IP') || '',
        country: cf.country || '',
        city: cf.city || '',
        region: cf.region || '',
        timezone: cf.timezone || '',
        lat: cf.latitude || 0,
        lon: cf.longitude || 0,
        asn: cf.asn ? 'AS' + cf.asn : '',
        isp: cf.asOrganization || '',
        countryCode: cf.country || '',
        zip: cf.postalCode || ''
      }), { headers });
    }

    // AbuseIPDB check
    if (abuseKey && abuseIp) {
      try {
        const resp = await fetch('https://api.abuseipdb.com/api/v2/check?ipAddress=' + encodeURIComponent(abuseIp) + '&maxAgeInDays=90&verbose', {
          headers: { 'Key': abuseKey, 'Accept': 'application/json' }
        });
        const data = await resp.text();
        return new Response(data, { headers });
      } catch (e) {
        return new Response(JSON.stringify({ error: 'AbuseIPDB request failed' }), { headers });
      }
    }

    // IPQS checks
    if (!key) {
      return new Response(JSON.stringify({ error: 'Missing API key' }), { headers });
    }

    let apiUrl = '';

    if (ip) {
      apiUrl = 'https://ipqualityscore.com/api/json/ip/' + key + '/' + ip + '?strictness=1&allow_public_access_points=true';
    } else if (email) {
      apiUrl = 'https://ipqualityscore.com/api/json/email/' + key + '/' + encodeURIComponent(email) + '?abuse_strictness=1';
    } else if (phone) {
      apiUrl = 'https://ipqualityscore.com/api/json/phone/' + key + '/' + encodeURIComponent(phone) + '?strictness=1';
    } else {
      return new Response(JSON.stringify({ error: 'Provide ip, email, phone, or abuseip' }), { headers });
    }

    try {
      const resp = await fetch(apiUrl);
      const data = await resp.text();
      return new Response(data, { headers });
    } catch (e) {
      return new Response(JSON.stringify({ error: 'Request failed' }), { headers });
    }
  }
};
