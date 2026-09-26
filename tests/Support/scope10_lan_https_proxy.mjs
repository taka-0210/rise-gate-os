import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';

const bind = process.env.S10_LAN_BIND || '127.0.0.1';
const allowedPrefix = process.env.S10_ALLOWED_PREFIX || '127.0.0.';
const caPort = Number(process.env.S10_CA_PORT || 8085);
const httpsPort = Number(process.env.S10_HTTPS_PORT || 8443);
const backendPort = Number(process.env.S10_BACKEND_PORT || 8774);
const caPath = process.env.S10_CA_CERT;
const keyPath = process.env.S10_SERVER_KEY;
const certPath = process.env.S10_SERVER_CERT;

if (!caPath || !keyPath || !certPath) throw new Error('Scope 10 TLS paths are required');

const address = request => (request.socket.remoteAddress || '').replace(/^::ffff:/, '');
const allowed = request => {
  const remote = address(request);
  return remote === '::1' || remote === '127.0.0.1' || remote.startsWith(allowedPrefix);
};
const reject = response => {
  response.writeHead(403, { 'Content-Type': 'text/plain', 'Cache-Control': 'no-store' });
  response.end('Forbidden');
};

const caServer = http.createServer((request, response) => {
  if (!allowed(request)) return reject(response);
  if (request.method !== 'GET' || request.url !== '/scope10-test-ca.cer') {
    response.writeHead(404, { 'Content-Type': 'text/plain', 'Cache-Control': 'no-store' });
    return response.end('Not Found');
  }
  response.writeHead(200, {
    'Content-Type': 'application/pkix-cert',
    'Content-Disposition': 'attachment; filename=company-os-scope10-test-ca.cer',
    'Cache-Control': 'no-store',
  });
  fs.createReadStream(caPath).pipe(response);
});

const httpsServer = https.createServer({
  key: fs.readFileSync(keyPath),
  cert: fs.readFileSync(certPath),
}, (request, response) => {
  if (!allowed(request)) return reject(response);
  const upstream = http.request({
    host: '127.0.0.1',
    port: backendPort,
    method: request.method,
    path: request.url,
    headers: {
      ...request.headers,
      host: request.headers.host,
      'x-forwarded-proto': 'https',
      'x-forwarded-for': address(request),
    },
  }, upstreamResponse => {
    const headers = { ...upstreamResponse.headers };
    if (headers.location) headers.location = headers.location.replace(/^http:/, 'https:');
    response.writeHead(upstreamResponse.statusCode || 502, headers);
    upstreamResponse.pipe(response);
  });
  upstream.on('error', () => {
    if (!response.headersSent) response.writeHead(502, { 'Content-Type': 'text/plain', 'Cache-Control': 'no-store' });
    response.end('Bad Gateway');
  });
  request.pipe(upstream);
});

caServer.listen(caPort, bind, () => {
  console.log(JSON.stringify({ service: 'scope10-ca', bind, port: caPort }));
});
httpsServer.listen(httpsPort, bind, () => {
  console.log(JSON.stringify({ service: 'scope10-https', bind, port: httpsPort, backendPort }));
});
