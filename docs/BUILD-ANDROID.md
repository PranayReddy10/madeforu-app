# Building the Android app

## What you need

- **Android Studio** (Ladybug 2024.2.1 or newer). It brings its own JDK
  and SDK, which is all the project needs.
- An internet connection for the first build: Gradle downloads AndroidX
  and Compose from Google's Maven repository.

Versions are pinned in `android/gradle/libs.versions.toml` — AGP 8.7.3,
Kotlin 2.0.21, Compose BOM 2024.12.01, minSdk 24, targetSdk 35.

## First build

1. **File → Open** and choose the `android/` folder (not the repository
   root — the Gradle project starts there).
2. Wait for the Gradle sync. Android Studio will offer to install any SDK
   piece it is missing; accept.
3. Plug in a phone with USB debugging on, or start an emulator.
4. **Run ▶**.

From the command line instead:

```bash
cd android
./gradlew assembleDebug          # app/build/outputs/apk/debug/app-debug.apk
./gradlew installDebug           # straight onto a connected phone
```

## The Gradle version is pinned — use the wrapper

`gradle/wrapper/gradle-wrapper.properties` pins **Gradle 8.11.1**, and the
wrapper downloads exactly that on first run, checking it against the
SHA-256 recorded beside it.

Always run `./gradlew`, never a `gradle` on your PATH. Without the wrapper
the build uses whatever version happens to be installed, and a mismatched
one produces noise like:

```
Deprecated Gradle features were used in this build, making it
incompatible with Gradle 10.0.
```

That message is a **warning, not an error** — the build still succeeds.
It appears when a pre-release Gradle (a 9.x milestone) runs Android Gradle
Plugin 8.7.3, which still calls APIs Gradle 9 has deprecated. The
deprecations are inside AGP, so there is nothing in this project to fix;
the answer is to run the version AGP supports, which is what the wrapper
now guarantees.

If you want to see which features a build is complaining about:

```bash
./gradlew assembleDebug --warning-mode all
```

Gradle and AGP move as a pair. When you bump one in
`gradle/libs.versions.toml`, bump the other in the wrapper properties to a
version its release notes list as supported — AGP 8.7 requires Gradle 8.9
or newer.

## There are no XML layouts — use Preview

This app is **Jetpack Compose**, so `res/layout/` does not exist and
Android Studio's Layout Editor has nothing to open. Screens are Kotlin
functions marked `@Composable`, in
`app/src/main/java/com/madeforu/sales/ui/`.

The Compose equivalent of the Layout Editor is the preview pane:

1. Open **`ui/Previews.kt`** (or any file containing `@Preview`).
2. Top-right of the editor, switch **Code → Split** (or **Design**).
3. Press **Build & Refresh** in that pane the first time.

`Previews.kt` renders the bill, the order rows, the product picker, the
KPI cards and every chart against sample data, each in light and dark. It
is design-time only — nothing in it runs in the app.

Previews need the project to compile, so if the pane says "Render problem"
or stays empty, build once (`./gradlew assembleDebug`) and hit refresh.

To see a real screen with real data there is no substitute for running the
app — a preview cannot call the server.

## If the Kotlin daemon "terminates unexpectedly"

```
The daemon has terminated unexpectedly on startup attempt #1
with error code: 0. The daemon process output:
    1. Kotlin compile daemon is ready
```

Read that carefully, because it is misleading. The daemon **started**, said
it was ready, and exited with code **0** — a clean exit. Nothing crashed
and nothing ran out of memory; an out-of-heap JVM dies with a non-zero
code and a "VM initialization" error. What failed is the handshake: the
Kotlin plugin forks a second JVM and talks to it over a local socket, and
the build could not attach to it.

So extra heap does not help. The usual blockers are:

- **Antivirus / endpoint security** intercepting the local socket.
  Windows Defender's controlled folder access and most corporate agents do
  this. It is by far the most common cause.
- A **VPN or firewall** rule that rewrites loopback traffic.
- A **stale daemon** left behind by an interrupted build.

`gradle.properties` already sets:

```properties
kotlin.compiler.execution.strategy=in-process
```

which compiles inside the Gradle daemon, so there is no second process and
no socket to block. For a project this size the cost is a second or two
per build.

If you would rather have the daemon back (marginally faster incremental
builds), comment that line out, uncomment `kotlin.daemon.jvmargs`, and
work through these in order:

```bash
./gradlew --stop                       # kill stale daemons
rm -rf ~/.kotlin/daemon                # Windows: %USERPROFILE%\.kotlin\daemon
./gradlew assembleDebug --no-daemon
```

Then check the JDK is consistent: **Settings → Build → Build Tools →
Gradle → Gradle JDK** should be the JetBrains Runtime that Android Studio
ships (JBR 21). A JDK picked up from `JAVA_HOME` that differs from the one
Studio runs on is the second most common cause.

If it still fails, add your project folder and Android Studio to your
antivirus exclusions — and if that is not something you can change on a
managed laptop, leave `in-process` on. It is a perfectly good setting, not
a workaround you need to feel bad about.

## Pointing it at your server

The default is baked into `app/build.gradle.kts`:

```kotlin
buildConfigField("String", "DEFAULT_BASE_URL", "\"https://sale.madeforu.co.in/api/\"")
```

The address is also editable in the app — **Settings → Server**, and on
the sign-in screen under "Use a different server", so a partner who mistypes
it can fix it without a new build. The trailing slash matters.

## Sharing it with the other partners

A debug APK installs fine on any phone with "install from unknown
sources" allowed, and is the quickest way to get this into four hands.

For something more permanent, build a signed release:

```bash
cd android
keytool -genkey -v -keystore madeforu.keystore -alias madeforu \
        -keyalg RSA -keysize 2048 -validity 10000
```

Add to `android/local.properties` (which git ignores — never commit a
keystore or its password):

```properties
MADEFORU_STORE_FILE=/absolute/path/madeforu.keystore
MADEFORU_STORE_PASSWORD=…
MADEFORU_KEY_ALIAS=madeforu
MADEFORU_KEY_PASSWORD=…
```

Then wire a `signingConfigs` block reading those properties, and run
`./gradlew assembleRelease`. **Keep the keystore file safe and backed up:**
losing it means no future version can update an installed app; every
partner would have to uninstall and reinstall.

The release build has R8 on. `app/proguard-rules.pro` already keeps the
kotlinx.serialization serializers — without those rules a release build
parses every API response into empty objects while the debug build works
perfectly, which is a confusing afternoon to lose.

## What the app needs at runtime

- `INTERNET` and `ACCESS_NETWORK_STATE`. That is the whole permission list.
- No camera, no storage, no contacts, no location. Bills are written to
  the app's own cache and shared through a `FileProvider`, so nothing asks
  for storage access on any Android version.
