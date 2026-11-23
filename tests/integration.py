#!/usr/bin/env python3
"""Regresión HTTP real para una instalación DE PRUEBAS XAMPP.
Crea datos ficticios: usar exclusivamente una BD local terminada en _qa.
Ejemplo QA contenedor: python tests/integration.py --base http://127.0.0.1:8087/tecnotrade/ --php-command 'docker exec -i tecnotrade-xampp-web php' --fixture-path /var/www/html/tecnotrade/tests/fixture.php --allow-test-writes
Sin Docker: --php-command C:/xampp/php/php.exe --fixture-path tests/fixture.php
Se espera propietario de prueba owner@example.test / Root-Test-Password-620! creado por instalar.php.
"""
import argparse, base64, concurrent.futures, datetime, hashlib, json, secrets, shlex, subprocess, sys, urllib.error, urllib.parse, urllib.request

p = argparse.ArgumentParser(description=__doc__)
p.add_argument('--base', required=True)
p.add_argument('--php-command', default='php')
p.add_argument('--fixture-path', default='tests/fixture.php')
p.add_argument('--owner-email', default='owner@example.test')
p.add_argument('--owner-password', default='Root-Test-Password-620!')
p.add_argument('--allow-test-writes', action='store_true')
a = p.parse_args()
base = a.base.rstrip('/') + '/'
u = urllib.parse.urlparse(base)
if u.hostname not in ('localhost', '127.0.0.1', '::1') or not a.allow_test_writes:
    sys.exit('Se requiere localhost y --allow-test-writes. No ejecutar sobre la tienda pública.')
origin = u.scheme+'://'+u.netloc
suffix = secrets.token_hex(4)
checks = []

def ok(value, description):
    assert value, description
    checks.append(description)
    print('OK', description, flush=True)

def fixture(op, **kwargs):
    proc = subprocess.run(shlex.split(a.php_command)+[a.fixture_path], input=json.dumps({'op':op, **kwargs}), text=True, capture_output=True, check=True)
    return json.loads(proc.stdout)

class Client:
    def __init__(self, cookie='', csrf=''):
        self.cookie, self.csrf = cookie, csrf
    def call(self, path, method='GET', body=None, expected=200, headers=None, raw=None):
        fullpath = path if path.startswith('/api/') else '/api'+path
        url = base+'api.php?path='+urllib.parse.quote(fullpath, safe='')
        h = {'Cookie':self.cookie} if self.cookie else {}
        if method not in ('GET','HEAD'):
            h.update({'Origin':origin,'X-CSRF-Token':self.csrf})
        data = raw
        if body is not None:
            data = json.dumps(body, separators=(',',':')).encode()
            h['Content-Type']='application/json'
        if headers: h.update(headers)
        req = urllib.request.Request(url, data=data, headers=h, method=method)
        try: response=urllib.request.urlopen(req,timeout=20)
        except urllib.error.HTTPError as e: response=e
        payload = response.read()
        for c in response.headers.get_all('Set-Cookie',[]):
            if c.startswith('tt_xampp_session='): self.cookie=c.split(';',1)[0]
        try: value=json.loads(payload)
        except Exception: raise AssertionError(f'{method} {path}: no es JSON HTTP{response.status}: {payload[:300]!r}')
        if isinstance(value,dict) and value.get('csrfToken'): self.csrf=value['csrfToken']
        codes = [expected] if isinstance(expected,int) else expected
        assert response.status in codes, f'{method} {path} HTTP{response.status}, esperaba{codes}: {value}'
        return value
    def init(self): return self.call('/session')
    def login(self,email,password):
        self.init()
        return self.call('/auth/login','POST',{'email':email,'password':password})
    def clone(self): return Client(self.cookie,self.csrf)

address = {'recipient':'Cliente de pruebas','phone':'5512345678','line':'Calle Pruebas 123','city':'Ciudad de México','postalCode':'01000'}
def key(label): return label+'-'+suffix
fixture('clear_rate')
admin=Client(); admin.login(a.owner_email,a.owner_password)
ok(admin.call('/me')['user']['role']=='admin','Propietario inicia sesión con cookie y rol admin')
clients={}
for label in ('alice','bob'):
    c=Client();c.init()
    r=c.call('/auth/register','POST',{'name':label+' Cliente','email':label+suffix+'@example.test','password':'Client-Password-620!','role':'admin'},201)
    clients[label]=c
    ok(r['user']['role']=='cliente','Registro público de '+label+' no eleva privilegios')
alice,bob=clients['alice'],clients['bob']
for role in ('tecnico','logistica','soporte'):
    email=role+suffix+'@example.test'
    admin.call('/admin/users','POST',{'name':'Personal '+role,'email':email,'password':'Staff-Password-620!','role':role},201)
    c=Client();c.login(email,'Staff-Password-620!');clients[role]=c
tech,logistic,support=[clients[x] for x in ('tecnico','logistica','soporte')]
alice.call('/admin/users',expected=403)
alice.call('/me','PATCH',{'name':'Mal origen'},403,{'Origin':'https://other.example'})
alice.call('/me','PATCH',{'name':'Sin CSRF'},403,{'X-CSRF-Token':''})
alice.call('/me','PATCH',{'name':'Sin origen'},403,{'Origin':''})
ok(True,'Cinco roles, CSRF y origen protegen operaciones')

cats=admin.call('/admin/categories')['categories']; category=cats[0]['id']
def product(name,price,stock=5,**kwargs):
    b={'name':name+' '+suffix,'categoryId':category,'condition':'Nuevo','description':'Fixture QA; no inventario comercial.','priceCents':price,'stock':stock,'published':True,'warrantyMonths':12,'image':'/assets/telefono.svg',**kwargs}
    return admin.call('/admin/products','POST',b,201)['product']
target=product('Equipo prueba alfa',100000)
cheap=product('Equipo prueba beta',40000)
draft=product('Borrador privado',20000,0,published=False)
s=alice.call('/search/suggestions?q=prueba')['suggestions']
ok(any(x['id']==target['id'] for x in s) and not any(x['id']==draft['id'] for x in s),'Búsquedas sugeridas consultan catálogo público')
image=target['image']
ok(image.startswith(u.path) and 'index.php?file=' in image,'Imágenes de API se adaptan a subcarpeta XAMPP')
with urllib.request.urlopen(origin+image) as r: ok(r.status==200 and r.headers['Content-Type'].startswith('image/svg'),'Imagen relativa se entrega correctamente')

b={'items':[{'productId':target['id'],'quantity':1,'unitPriceCents':1}],'address':address,'useWallet':False,'idempotencyKey':key('order'),'totalCents':1}
order=alice.call('/orders','POST',b,201)['order']
ok(order['totalCents']==100000 and order['amountDueCents']==100000 and order['status']=='Pendiente de pago','Compra usa precios del servidor y no simula pagos')
repeat=alice.call('/orders','POST',b,201)['order']
ok(repeat['id']==order['id'],'Compra idempotente no duplica pedido')
alice.call('/orders','POST',{**b,'items':[{'productId':target['id'],'quantity':2}]},409)
bob.call('/orders/'+order['id'],expected=[403,404])
logistic.call('/orders/'+order['id']+'/status','PATCH',{'status':'En camino','location':'Centro QA'},[400,409])
alice.call('/orders/'+order['id']+'/cancel','POST',{})
alice.call('/orders/'+order['id']+'/cancel','POST',{})
ok(alice.call('/products/'+target['id'])['product']['stock']==5,'Cancelar restaura inventario exactamente una vez')
for bad in ('1',1.5,-1,True):
    alice.call('/orders','POST',{**b,'idempotencyKey':key('bad-qty-'+str(bad)),'items':[{'productId':target['id'],'quantity':bad}]},400)
ok(True,'Cantidades inválidas y claves reutilizadas se rechazan')

# Archivos privados y rechazo de contenido incompatible.
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jH6kAAAAASUVORK5CYII=')
photo=alice.call('/uploads?purpose=tradein','POST',expected=201,headers={'Content-Type':'image/png'},raw=png)['photo']
alice.call('/uploads?purpose=tradein','POST',expected=415,headers={'Content-Type':'image/png'},raw=b'<script>bad</script>')
def media_status(client):
    req=urllib.request.Request(origin+photo['url'],headers={'Cookie':client.cookie})
    try:r=urllib.request.urlopen(req)
    except urllib.error.HTTPError as e:r=e
    return r.status
ok(media_status(alice)==200 and media_status(bob) in (403,404) and media_status(Client()) in (401,403,404),'Fotos de canje accesibles sólo a propietario/personal permitido')

# Canje real: inspección -> aceptación -> saldo sobrante.
model=alice.call('/models')['models'][0]['modelKey']
def new_trade(serial_suffix=''):
    return alice.call('/tradeins','POST',{'targetProductId':target['id'],'modelKey':model,'condition':'bueno','notes':'Equipo propio; flujo ficticio QA.','photoIds':[photo['id']]},201)['tradein']
t=new_trade()
inspection={'decision':'approve','offerCents':150000,'serial':'QA-'+suffix+'-001','checks':[True]*6,'batteryPercent':92,'grade':'A','notes':'Propiedad y funcionamiento revisados en QA.'}
alice.call('/tradeins/'+t['id']+'/inspection','PATCH',inspection,403)
tech.call('/tradeins/'+t['id']+'/inspection','PATCH',inspection)
ok(alice.call('/wallet')['balanceCents']==0,'Una oferta técnica aún sin aceptar no crea crédito')
t2=new_trade();tech.call('/tradeins/'+t2['id']+'/inspection','PATCH',{**inspection,'serial':inspection['serial'].replace('-','').lower()},409)
accept={'expectedPriceCents':100000,'expectedOfferCents':150000,'idempotencyKey':key('accept'),'useWallet':False,'address':address}
alice.call('/tradeins/'+t['id']+'/accept','POST',{**accept,'expectedOfferCents':1},409)
alice.call('/tradeins/'+t['id']+'/accept','POST',{**accept,'expectedPriceCents':1},409)
alice.call('/tradeins/'+t['id']+'/accept','POST',{**accept,'idempotencyKey':b['idempotencyKey']},409)
alice.call('/tradeins/'+t['id']+'/accept','POST',{**accept,'address':{}},400)
ok(alice.call('/wallet')['balanceCents']==0 and alice.call('/products/'+target['id'])['product']['stock']==5,'Errores de aceptación revierten saldo e inventario')
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as ex:
    accepted=list(ex.map(lambda _:alice.clone().call('/tradeins/'+t['id']+'/accept','POST',accept),range(6)))
ok(len({r['order']['id'] for r in accepted})==1 and alice.call('/wallet')['balanceCents']==50000,'Aceptaciones simultáneas crean un solo pedido y conservan excedente50000')
ok(alice.call('/products/'+target['id'])['product']['stock']==4,'Canje consume inventario sólo una vez')
tech.call('/tradeins/'+t['id']+'/inspection','PATCH',{**inspection,'decision':'reject'},409)
wallet_order=alice.call('/orders','POST',{'items':[{'productId':cheap['id'],'quantity':1}],'address':address,'useWallet':True,'idempotencyKey':key('wallet-order')},201)['order']
ok(wallet_order['walletAppliedCents']==40000 and wallet_order['amountDueCents']==0 and alice.call('/wallet')['balanceCents']==10000,'Crédito sobrante paga una compra posterior')

# Concurrencia sobre una última unidad de inventario.
last=product('Última unidad QA',30000,1)
def compete(pair):
    c,label=pair
    try:return c.clone().call('/orders','POST',{'items':[{'productId':last['id'],'quantity':1}],'address':address,'useWallet':False,'idempotencyKey':key('race-'+label)},[201,409])
    except Exception:raise
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as ex:
    race=list(ex.map(compete,[(alice,'a'),(bob,'b')]))
ok(sum('order' in x for x in race)==1 and alice.call('/products/'+last['id'])['product']['stock']==0,'Dos compradores simultáneos no venden la misma última unidad')

# Pago manual, logística y soporte.
pending=bob.call('/orders','POST',{'items':[{'productId':target['id'],'quantity':1}],'address':address,'useWallet':False,'idempotencyKey':key('manual-pay')},201)['order']
bob.call('/admin/orders/'+pending['id']+'/payments','POST',{'amountCents':100000,'reference':key('payment')},403)
admin.call('/admin/orders/'+pending['id']+'/payments','POST',{'amountCents':100001,'reference':key('payment')},409)
admin.call('/admin/orders/'+pending['id']+'/payments','POST',{'amountCents':100000,'reference':key('payment'),'note':'Recibo ficticio sólo QA.'},201)
for status in ('Preparando','En camino','Entregado'):
    logistic.call('/orders/'+pending['id']+'/status','PATCH',{'status':status,'location':'Centro de pruebas','carrier':'Reparto propio','tracking':''})
ok(bob.call('/orders/'+pending['id'])['order']['status']=='Entregado','Pago manual autorizado y seguimiento de entrega completo')
ticket=alice.call('/tickets','POST',{'reason':'Consulta QA','message':'Necesito confirmar las condiciones del equipo.'},201)['ticket']
bob.call('/tickets/'+ticket['id'],expected=[403,404])
support.call('/tickets/'+ticket['id']+'/messages','POST',{'message':'El área de soporte responde la consulta QA.','status':'Resuelto'})
opened=alice.call('/tickets/'+ticket['id']+'/messages','POST',{'message':'Tengo otra duda del equipo.'})['ticket']
ok(opened['status']=='Abierto' and len(opened['messages'])==3,'Soporte responde y cliente reabre sólo su propio ticket')

# Reacondicionados necesitan dictamen técnico antes de publicación.
used=product('Seminuevo privado QA',55000,1,condition='Seminuevo',published=False)
admin.call('/admin/products/'+used['id'],'PATCH',{'published':True},409)
rev=next(x for x in tech.call('/reviews')['reviews'] if x['productId']==used['id'])
admin.call('/reviews/'+rev['id'],'PATCH',inspection,403)
tech.call('/reviews/'+rev['id'],'PATCH',{**inspection,'serial':'REV-'+suffix})
admin.call('/admin/products/'+used['id'],'PATCH',{'published':True})
ok(alice.call('/products/'+used['id'])['product']['approved'],'Publicación seminuevo exige inspección independiente del técnico')

# Datos de mercado caducados no producen una oferta ficticia automática.
manual=next((m for m in alice.call('/models')['models'] if not m['canQuote']),None)
if manual:
    alice.call('/tradeins/quote','POST',{'modelKey':manual['modelKey'],'condition':'bueno'},422)
    ok(True,'Valoración automática rechaza referencias insuficientes o vencidas')

# Recovery response has no account enumeration. Token fixture never appears in HTTP.
known=alice.call('/auth/forgot-password','POST',{'email':'alice'+suffix+'@example.test'})
unknown=bob.call('/auth/forgot-password','POST',{'email':'nobody'+suffix+'@example.test'})
ok(known==unknown and 'token' not in json.dumps(known),'Recuperación no enumera cuentas ni entrega token por API')
token='QA-token-'+secrets.token_urlsafe(24)
fixture('reset_token',email='alice'+suffix+'@example.test',token=token)
bob.call('/auth/reset-password','POST',{'token':token,'password':'New-Client-Password-920!'})
bob.call('/auth/reset-password','POST',{'token':token,'password':'Another-Password-920!'},400)
alice.call('/me',expected=401)
fresh=Client();fresh.login('alice'+suffix+'@example.test','New-Client-Password-920!')
wrong=Client();wrong.init();wrong.call('/auth/login','POST',{'email':'alice'+suffix+'@example.test','password':'Client-Password-620!'},401)
ok(True,'Restablecer contraseña consume token e invalida sesiones anteriores')

# Límites persisten incluso cuando la solicitud de login revierte su transacción.
limited=Client(); limited.init()
for attempt in range(5):
    limited.call('/auth/login','POST',{'email':'ratelimit'+suffix+'@example.test','password':'Incorrect-Password-620!'},401)
limited.call('/auth/login','POST',{'email':'ratelimit'+suffix+'@example.test','password':'Incorrect-Password-620!'},429)
ok(True,'Intentos fallidos conservan límite de acceso tras rollback SQL')
admin_user=admin.call('/me')['user']
admin.call('/admin/users/'+admin_user['id'],'PATCH',{'active':False},409)
admin.call('/admin/users/'+admin_user['id'],'PATCH',{'role':'cliente'},409)
ok(True,'Administrador no puede desactivar ni cambiar su propio rol')
constraints=fixture('constraints')
ok(all(constraints.values()) and len(constraints)==3,'CHECK y FOREIGN KEY MySQL rechazan stock/precio negativos y referencias inexistentes')

# Recursos privados y constraints SQL comprobados al final.
for path in ('server/config.local.php','server/common.php','database/schema.sql','tests/fixture.php','CONTRATO-INTERNO.md'):
    try:r=urllib.request.urlopen(base+path)
    except urllib.error.HTTPError as e:r=e
    ok(r.status in (403,404),'Apache bloquea acceso a '+path)
inv=fixture('invariants')
ok(all(inv[k]==0 for k in ('negativeWallets','mismatchedLedgers','negativeStock','unhashedSessions')) and inv['tables']>=22,'MySQL conserva invariantes: ledger, stock y tokens hash')
print(json.dumps({'passed':len(checks),'base':base,'fixtures':suffix,'invariants':inv},ensure_ascii=False))
