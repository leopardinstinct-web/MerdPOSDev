# MerdPOS Attendance Display

Small landscape Android app that provisions once online, registers an Ed25519 public key against an already activated MerdPOS device, then displays rotating signed attendance QRs offline.

Build with the project Flutter version:

```sh
flutter pub get
flutter analyze
flutter test
flutter build apk --release
```

Production release signing must replace debug signing before distribution. The activation token is used only during provisioning and is not persisted. The private signing seed is stored with Android encrypted storage.
