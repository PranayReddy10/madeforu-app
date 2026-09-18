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
