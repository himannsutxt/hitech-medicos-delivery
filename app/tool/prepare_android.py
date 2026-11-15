#!/usr/bin/env python3
"""Patches the Android project that `flutter create` generates in CI:
permissions, app name, icons, package visibility queries and release signing."""
import os, re, shutil, sys, glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
AND = os.path.join(ROOT, 'android')
man_path = os.path.join(AND, 'app/src/main/AndroidManifest.xml')
man = open(man_path, encoding='utf-8').read()

perms = [
    'android.permission.INTERNET',
    'android.permission.ACCESS_FINE_LOCATION',
    'android.permission.ACCESS_COARSE_LOCATION',
    'android.permission.ACCESS_BACKGROUND_LOCATION',
    'android.permission.FOREGROUND_SERVICE',
    'android.permission.FOREGROUND_SERVICE_LOCATION',
    'android.permission.POST_NOTIFICATIONS',
    'android.permission.CAMERA',
    'android.permission.WAKE_LOCK',
    'android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS',
]
block = ''.join(f'    <uses-permission android:name="{p}"/>\n' for p in perms if p not in man)
man = re.sub(r'(<manifest[^>]*>\n)', r'\1' + block.replace('\\', '\\\\'), man, count=1)
man = re.sub(r'android:label="[^"]*"', 'android:label="Hitech Delivery"', man, count=1)
queries = '''    <queries>
        <intent><action android:name="android.intent.action.DIAL"/><data android:scheme="tel"/></intent>
        <intent><action android:name="android.intent.action.VIEW"/><data android:scheme="https"/></intent>
        <intent><action android:name="android.intent.action.VIEW"/><data android:scheme="geo"/></intent>
        <intent><action android:name="android.media.action.IMAGE_CAPTURE"/></intent>
    </queries>
'''
inner = '\n'.join(queries.strip().splitlines()[1:-1]) + '\n'
if '<queries>' in man:
    man = man.replace('</queries>', inner + '    </queries>', 1)
else:
    man = man.replace('</manifest>', queries + '</manifest>')
open(man_path, 'w', encoding='utf-8').write(man)

# Icons
for src in glob.glob(os.path.join(ROOT, 'android_overrides/res/mipmap-*/ic_launcher.png')):
    d = os.path.basename(os.path.dirname(src))
    dst = os.path.join(AND, 'app/src/main/res', d)
    os.makedirs(dst, exist_ok=True)
    shutil.copy(src, os.path.join(dst, 'ic_launcher.png'))

# Gradle: minSdk 23 and release signing from environment (falls back to debug key)
gk = os.path.join(AND, 'app/build.gradle.kts')
gg = os.path.join(AND, 'app/build.gradle')
if os.path.exists(gk):
    g = open(gk).read()
    g = re.sub(r'minSdk\s*=\s*[^\n]+', 'minSdk = 23', g, count=1)
    if 'HMD_KEYSTORE' not in g:
        g = g.replace('buildTypes {', '''signingConfigs {
        create("hmd") {
            val ks = System.getenv("HMD_KEYSTORE")
            if (ks != null && file(ks).exists()) {
                storeFile = file(ks)
                storePassword = System.getenv("HMD_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("HMD_KEY_ALIAS") ?: "hitech"
                keyPassword = System.getenv("HMD_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {''', 1)
        g = g.replace('signingConfig = signingConfigs.getByName("debug")', 'signingConfig = if (System.getenv("HMD_KEYSTORE") != null) signingConfigs.getByName("hmd") else signingConfigs.getByName("debug")')
    open(gk, 'w').write(g)
elif os.path.exists(gg):
    g = open(gg).read()
    g = re.sub(r'minSdk(Version)?\s*=?\s*[^\n]+', 'minSdk = 23', g, count=1)
    if 'HMD_KEYSTORE' not in g:
        g = g.replace('buildTypes {', '''signingConfigs {
        hmd {
            def ks = System.getenv("HMD_KEYSTORE")
            if (ks != null && file(ks).exists()) {
                storeFile file(ks)
                storePassword System.getenv("HMD_KEYSTORE_PASSWORD")
                keyAlias(System.getenv("HMD_KEY_ALIAS") ?: "hitech")
                keyPassword System.getenv("HMD_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {''', 1)
        g = g.replace('signingConfig = signingConfigs.debug', 'signingConfig = System.getenv("HMD_KEYSTORE") != null ? signingConfigs.hmd : signingConfigs.debug')
        g = g.replace('signingConfig signingConfigs.debug', 'signingConfig System.getenv("HMD_KEYSTORE") != null ? signingConfigs.hmd : signingConfigs.debug')
    open(gg, 'w').write(g)
else:
    sys.exit('No app build.gradle found')
print('Android project patched: permissions, label, icons, minSdk 23, signing')
