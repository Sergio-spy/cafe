# ☕ Café — App de pagos para el grupo

Aplicación web para registrar quién paga los cafés del grupo.  
Stack: PHP + SQLite + HTML/CSS/JS puro. Sin dependencias, sin npm.

---

## Archivos

```
cafe/
├── index.html      ← Frontend (app completa)
├── api.php         ← Backend (API REST)
├── .htaccess       ← Seguridad Apache
├── cafe.db         ← Se crea automáticamente al primer uso
└── README.md
```

---

## Instalación (5 minutos)

### 1. Cambiar el token secreto

Abre **AMBOS archivos** y reemplaza `CAMBIA_ESTE_TOKEN_POR_UNO_SEGURO`  
por una cadena aleatoria larga (mínimo 32 caracteres).

**api.php**, línea ~12:
```php
define('SECRET_TOKEN', 'tu_token_secreto_aqui_muy_largo_y_aleatorio');
```

**index.html**, línea dentro del `<script>`:
```js
const TOKEN = 'tu_token_secreto_aqui_muy_largo_y_aleatorio';
```

El token debe ser **idéntico** en ambos archivos.

Puedes generar uno en: https://passwordsgenerator.net/

### 2. Subir por FTP

Sube toda la carpeta `cafe/` a tu hosting. Ejemplo:
- `public_html/cafe/` → accesible en `tudominio.com/cafe/`
- `public_html/` → si quieres que sea la raíz

### 3. Permisos

Asegúrate de que el directorio donde subes los archivos  
sea **escribible** por PHP (permisos 755 en el directorio).  
El archivo `cafe.db` se creará automáticamente la primera vez.

Si tu hosting no permite escritura en `public_html/`,  
cambia la ruta en `api.php`:
```php
define('DB_PATH', '/home/tuusuario/cafe.db');  // fuera de public_html
```

### 4. Verificar que PHP funciona

Visita `tudominio.com/cafe/api.php/stats` en el navegador.  
Deberías ver: `{"error":"No autorizado"}` — esto es correcto (falta el token).

---

## Uso

1. Abre `tudominio.com/cafe/` en el móvil
2. Ve a **Personas** → añade a todos los del grupo
3. En **Inicio** verás quién toca pagar
4. Cuando pague, pulsa **Registrar pago** e introduce el importe

---

## Seguridad

- La base de datos (`cafe.db`) está protegida por `.htaccess` — no es accesible desde el navegador
- El token en el header `X-Token` protege la API de accesos externos
- Para máxima seguridad, mueve `cafe.db` fuera de `public_html/`
- Usa HTTPS en tu dominio (la mayoría de hostings lo ofrecen gratis con Let's Encrypt)

---

## Personalización

### Cambiar el nombre de la app
En `index.html`, busca `café ☕` en la etiqueta `<title>` y `.topbar-title`.

### Añadir más personas con colores
Los colores disponibles son: `c-amber`, `c-green`, `c-red`, `c-blue`, `c-purple`, `c-teal`

### Cambiar el algoritmo de turnos
En `api.php`, función `score`:
```php
$score = ($times * $avgRound) + $total;
```
- Si quieres priorizar solo el **número de veces**: usa solo `$times`
- Si quieres priorizar solo el **importe total**: usa solo `$total`

---

## Requisitos del hosting

- PHP 7.4 o superior (recomendado PHP 8.x)
- Extensión PDO SQLite (casi todos los hostings compartidos la incluyen)
- Apache con mod_rewrite (para el .htaccess)

---

## ¿Problemas?

**Error 500**: Revisa que PHP tiene permiso de escritura en el directorio.  
**"No autorizado"**: El token en `index.html` y `api.php` no coinciden.  
**Página en blanco**: Activa `display_errors` temporalmente en `api.php` para ver el error.

---

## Instalar en el móvil (icono en pantalla de inicio)

La app es una **PWA** — se instala desde el navegador, sin App Store ni Google Play.

### Android (Chrome)
1. Abre `tudominio.com/cafe/` en Chrome
2. Aparecerá un banner: **"Añadir a pantalla de inicio"** — pulsa Añadir
3. Si no aparece: menú `⋮` → **"Añadir a pantalla de inicio"**

### iPhone / iPad (Safari)
1. Abre `tudominio.com/cafe/` en **Safari** (imprescindible, no Chrome)
2. Pulsa el botón de **compartir** (cuadrado con flecha ↑, parte inferior)
3. **"Añadir a pantalla de inicio"** → Añadir

Manda estas instrucciones por WhatsApp al grupo y listo.
