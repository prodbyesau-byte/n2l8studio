# N2L8 STUDIO — Mobile Application (iOS & Android)

This repository houses the cross-platform **Flutter** mobile application codebase for **N2L8 STUDIO**. It wraps the responsive retro-futuristic PHP music platform into a highly optimized, high-fidelity app-store-ready package.

---

## Features
- ** Fallout Terminal Aesthetic**: Custom native animated boot loader splash screen, phosphor green circular indicators, and amber-themed CRT connection-failure screens.
- **Advanced Web Container**: Leverages `InAppWebView` with persistent cookie syncing, allowing users to stay logged in seamlessly.
- **True App Feel Overlay**: Custom native bottom navigation synced with page transitions, native pull-to-refresh, linear glowing load progress tracking, and physical back-button intercepts.
- **Automated Web-Strip Injection**: Automatically injects CSS rules to hide web-only navigational headers and footers, creating a unified layout.
- **Hardware Integration**: Pre-configured permission channels for Camera, Photos, and Media libraries, enabling users to upload profiles and save media.

---

## Technical Stack & Structure
- **Framework**: Flutter SDK (Dart)
- **Primary Dependencies**:
  - `flutter_inappwebview` (Core browser engine)
  - `connectivity_plus` (Offline monitoring)
  - `flutter_dotenv` (Isolated configuration)
  - `google_fonts` (`Righteous` & `VT323` CRT integrations)
  - `url_launcher` (Safe browser redirecting for Stripe/external checkout links)

```
n2l8-mobile/
├── android/            (Android native structure, builds, and AndroidManifest)
├── ios/                (iOS native configuration and Info.plist)
├── assets/             (Logo and mascots)
├── lib/
│   ├── theme/
│   │   └── app_theme.dart     (Fallout terminal typography, shadows, colors)
│   ├── screens/
│   │   ├── splash_screen.dart (Typewriter CRT boot console loader)
│   │   ├── dashboard_screen.dart (Core WebView + Bottom Nav integration)
│   │   └── offline_screen.dart   (Connectivity failure warning module)
│   └── main.dart              (Main app entry point and system setup)
├── .env                (Environment configuration variables - never commit!)
├── pubspec.yaml        (Flutter dependency configurations)
└── README.md           (This developer manual)
```

---

## 1. Development & Setup Guide

### Prerequisites
1. **Flutter SDK**: Install Flutter (`>= 3.0.0`) on your machine ([Flutter Installation Guide](https://docs.flutter.dev/get-started/install)).
2. **Android Studio**: Installed with Android SDK command-line tools (for Android testing).
3. **Xcode** (macOS only): Required to compile and test on iOS simulators/devices.

### Quick Setup Steps
1. Navigate to the mobile app directory:
   ```bash
   cd n2l8-mobile
   ```
2. Fetch package dependencies:
   ```bash
   flutter pub get
   ```
3. Verify your local environment and toolchains are connected:
   ```bash
   flutter doctor
   ```

---

## 2. Secure Keys & Environment Variables

All app settings are isolated inside the `.env` file in the root of the `n2l8-mobile` directory. This is excluded from versions to protect sensitive properties.

```env
APP_NAME="N2L8 STUDIO"
BASE_URL="https://n2l8studio.dk"
USER_AGENT_SUFFIX="N2L8StudioMobileApp"
ONESIGNAL_APP_ID="YOUR_ONESIGNAL_APP_ID_HERE"
```

> [!CAUTION]
> **Security Protocol**: Never commit `.env` or plain-text credentials to public code repositories. Always add `.env` to `.gitignore`.

---

## 3. Local Testing Instructions

You can run the application on physical devices or local software emulators.

### Check Connected Devices
Ensure you have an emulator running or a physical device connected via USB with developer tools enabled, then run:
```bash
flutter devices
```

### Run in Debug Mode (Hot Reload enabled)
Compile and launch on your active target:
```bash
flutter run
```

*Press `r` in the terminal while running to trigger **Hot Reload** instantly, or `R` to trigger a **Hot Restart**.*

---

## 4. Release Compilation & Store Publishing

When you are ready to prepare files for publishing on Google Play Store and Apple App Store, execute the following build instructions:

### A. Android Release (APK & AAB)

Google Play requires the **AAB (Android App Bundle)** format for uploads. For manual side-loading, compile an **APK**.

#### Step 1: Configure App Signing (Crucial)
1. Generate an upload keystore file (if you do not have one):
   ```bash
   keytool -genkey -v -keystore android/app/upload-keystore.jks -keyalg RSA -keysize 2048 -validity 10000 -alias upload
   ```
2. Create `android/key.properties` (never upload to git) containing:
   ```properties
   storePassword=YOUR_KEYSTORE_PASSWORD
   keyPassword=YOUR_KEY_PASSWORD
   keyAlias=upload
   storeFile=upload-keystore.jks
   ```

#### Step 2: Build APK (Manual installs)
```bash
flutter build apk --release
```
*Outputs: `build/app/outputs/flutter-apk/app-release.apk`*

#### Step 3: Build AAB (Google Play Store upload)
```bash
flutter build appbundle --release
```
*Outputs: `build/app/outputs/bundle/release/app-release.aab`*

---

### B. iOS Release (App Store IPA Archive)

To build for Apple devices, you must operate on a **macOS machine** and have an active **Apple Developer Account** ($99/year).

#### Step 1: Configure Certificates and Provisioning Profiles
1. Open Xcode by launching the native workspace:
   ```bash
   open ios/Runner.xcworkspace
   ```
2. Select the `Runner` project in the left sidebar, navigate to the **Signing & Capabilities** tab.
3. Select your active Developer Team and resolve bundle identifier registering (e.g. `com.n2l8studio.app`).

#### Step 2: Build CocoaPods dependencies
Ensure iOS native library dependencies are synced:
```bash
cd ios
pod install
cd ..
```

#### Step 3: Compile and Archive IPA
Run the clean-release command to compile and bundle:
```bash
flutter build ipa --release
```
This builds and archives the application. 
- Open **Xcode Organizer** (`Window -> Organizer`), select the archive, and click **Distribute App** to upload to App Store Connect / TestFlight.

---

## 5. Troubleshooting & Integration Notes

- **Stripe / External payment redirects**: When checkout links are loaded (e.g. `checkout.stripe.com`), the WebView intercepts the event and automatically hands the checkout over to the default native device browser for 100% security, returning the user once finished.
- **Clearing Cache**: If you update forum layouts or shop beats on the backend and want to force an update on clients, clicking the **Sync** icon on the top right reloads the frame, and clearing the app storage refreshes all assets.
