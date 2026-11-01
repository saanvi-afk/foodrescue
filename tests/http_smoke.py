"""Real HTTP checks with stdlib only. Requires migrated disposable MySQL test DB."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BINARY', 'php')
environment = os.environ.copy()
for line in (ROOT / '.env').read_text().splitlines():
    if line and not line.startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        environment.setdefault(key.strip(), value.strip().strip('\"\''))
database = environment.get('TEST_DB_DATABASE', 'foodrescue_test')
assert re.fullmatch(r'[a-zA-Z0-9_]+_test', database), 'Must use a disposable _test database'
environment['DB_DATABASE'] = database
roles = ['donor', 'receiver', 'volunteer', 'super_admin', 'researcher']
password = 'http-test-long-password'
stamp = str(time.time_ns())
# Trusted CLI fixture provisioning, never a public role-assignment endpoint.
fixture = '''require 'config/bootstrap.php';
$data=json_decode(stream_get_contents(STDIN),true);
$db=FoodRescue\\Core\\Database::connection();
$repo=new FoodRescue\\Models\\UserRepository($db);
foreach ($data['roles'] as $role) {
$repo->create('HTTP Test '.$role,$role.'.'.$data['stamp'].'@example.test',$data['password'],$role);
}
'''
subprocess.run([PHP, '-r', fixture], cwd=ROOT, env=environment,
               input=json.dumps(dict(roles=roles, stamp=stamp, password=password)), text=True, check=True)
with socket.socket() as reservation:
    reservation.bind(('127.0.0.1', 0))
    port = reservation.getsockname()[1]
base = f'http://127.0.0.1:{port}'
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
checks = 0

def fetch(path, data=None, csrf=None, raw=None):
    headers = {}
    payload = None
    if data is not None or raw is not None:
        payload = raw if raw is not None else json.dumps(data).encode()
        headers['Content-Type'] = 'application/json'
        if csrf is not None:
            headers['X-CSRF-Token'] = csrf
    try:
        response = client.open(urllib.request.Request(base + path, data=payload, headers=headers))
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode(), response.headers

def check(condition, name):
    global checks
    assert condition, name
    checks += 1
    print('PASS', name)

with tempfile.TemporaryDirectory(prefix='foodrescue-sessions-') as sessions, tempfile.TemporaryFile() as log:
    server = subprocess.Popen([PHP, '-d', 'display_errors=0', '-d', f'session.save_path={sessions}',
                               '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'],
                              cwd=ROOT, env=environment, stdout=log, stderr=log)
    try:
        for _ in range(50):
            try:
                status, page, headers = fetch('/')
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise RuntimeError('Server did not start')
        check(status == 200 and 'Good food.' in page, 'Landing page renders')
        check('Demo12345678!' not in page and all(role + '@foodrescue.test' not in page for role in ['admin','donor','receiver','volunteer','researcher']), 'Landing page does not expose demo credentials')
        check("frame-ancestors 'none'" in headers['Content-Security-Policy'], 'Security headers present')
        check('HttpOnly' in headers.get('Set-Cookie', ''), 'Session cookie is HttpOnly')
        csrf = re.search(r'name="csrf-token" content="([a-f0-9]+)"', page)[1]
        check(fetch('/dashboard')[1].find('Welcome back.') >= 0, 'Anonymous dashboard redirects to login')
        check(fetch('/.env')[0] == 404 and fetch('/config/bootstrap.php')[0] == 404, 'Private project files inaccessible')
        check(fetch('/assets/css/app.css')[0] == 200, 'CSS asset served')
        check(fetch('/api/auth/login', {})[0] == 419, 'Missing CSRF rejected over HTTP')
        check(fetch('/api/auth/login', csrf=csrf, raw=b'{broken')[0] == 400, 'Malformed JSON rejected')
        check(fetch('/api/auth/register', {'name': []}, csrf)[0] == 422, 'Structured field injection rejected')
        check(fetch('/api/auth/register', {'name': 'Intruder', 'email': 'intruder@example.test', 'password': password, 'role': 'super_admin'}, csrf)[0] == 422, 'Public privileged registration rejected')
        check(fetch('/api/auth/login', {'email': 'unknown@example.test', 'password': password}, csrf)[0] == 401, 'Invalid login rejected over HTTP')
        for role in roles:
            status, body, _ = fetch('/api/auth/login', {'email': f'{role}.{stamp}@example.test', 'password': password}, csrf)
            check(status == 200, f'{role} login succeeds')
            result = json.loads(body)
            new_csrf = result['csrf']
            check(new_csrf != csrf, f'{role} login rotates CSRF')
            csrf = new_csrf
            path = {'super_admin': '/admin', 'researcher': '/research'}.get(role, '/' + role)
            status, page, _ = fetch(path)
            check(status == 200 and 'HTTP Test ' + role in page, f'{role} workspace renders account data')
            other = '/donor' if role != 'donor' else '/research'
            check(fetch(other)[0] == 403, f'{role} cross-role access denied')
            status, body, _ = fetch('/api/auth/logout', {}, csrf)
            check(status == 200, f'{role} logout succeeds')
            csrf = json.loads(body)['csrf']
        if environment.get('APP_ENV') == 'local' and environment.get('DEMO_MODE') == 'true':
            # Begin a fresh login-rate-limit scenario in the disposable test database.
            subprocess.run([PHP, '-r', "require 'config/bootstrap.php'; FoodRescue\\Core\\Database::connection()->exec('DELETE FROM auth_rate_limits');"], cwd=ROOT, env=environment, check=True)
            login_page = fetch('/login')[1]
            check(all(role + '@foodrescue.test' not in login_page for role in ['admin','donor','receiver','volunteer','researcher']) and 'Demo12345678!' not in login_page, 'Login page does not expose demo credentials')
            check(fetch('/api/auth/demo-login', {'role': 'donor'}, csrf)[0] == 404, 'Password-free role entry endpoint is absent')
            for role in roles:
                email_role = 'admin' if role == 'super_admin' else role
                status, body, _ = fetch('/api/auth/login', {'email': email_role + '@foodrescue.test', 'password': 'Demo12345678!'}, csrf)
                check(status == 200, f'{role} demo signs in with default credentials')
                csrf = json.loads(body)['csrf']
                path = {'super_admin': '/admin', 'researcher': '/research'}.get(role, '/' + role)
                status, page, _ = fetch(path)
                check(status == 200 and 'Verification pending' not in page, f'{role} demo workspace is email verified')
                status, body, _ = fetch('/api/auth/logout', {}, csrf)
                csrf = json.loads(body)['csrf']
        status, body, _ = fetch('/api/auth/register', {'name': '<script>unsafe</script>', 'email': f'new.{stamp}@example.test', 'password': password, 'role': 'receiver'}, csrf)
        check(status == 201, 'Public receiver registration succeeds')
        csrf = json.loads(body)['csrf']
        page = fetch('/dashboard')[1]
        check('&lt;script&gt;unsafe&lt;/script&gt;' in page and '<script>unsafe</script>' not in page, 'Dashboard escapes user text')
        status, body, _ = fetch('/api/auth/resend-verification', {}, csrf)
        check(status == 200, 'Authenticated verification resend succeeds')
        print(f'{checks} HTTP checks passed')
    finally:
        server.terminate()
        server.wait(timeout=10)
