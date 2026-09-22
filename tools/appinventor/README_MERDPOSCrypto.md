# MERDPOSCrypto App Inventor extension

`MERDPOSCrypto.aix` is the POS-side helper for MERDPOS compact attendance QR v2.

It exposes these App Inventor blocks:
- `GenerateNonceHex` → 16 uppercase hexadecimal characters from Android `SecureRandom`.
- `GenerateDeviceKey` → creates/reuses an Ed25519 key and returns the raw 32-byte public key as standard Base64.
- `GetPublicKeyBase64` → returns the registered public-key material.
- `HasDeviceKey` → checks that the locally protected key can be opened.
- `Sign(message)` → Ed25519 signature encoded Base64URL without padding.
- `CreateAttendanceToken(deviceCode, timestamp)` → returns `DDDDMMDDYYYYHHMMSS<16 HEX NONCE>.<signature>`.
- `ResetDeviceKey` → deletes the local signing key. A replacement public key must then be registered in MERDPOS.
- `GetLastError` → blank on success, otherwise the latest extension error.

The extension requires Android 6.0 / API 23 or newer. The Ed25519 seed is encrypted at rest with an AES-GCM wrapping key generated in Android Keystore. The wrapping key is non-exportable. The public key is not secret.
The Ed25519 implementation bundled into the extension is EdDSA-Java 0.3.0 (`net.i2p.crypto:eddsa`), released under CC0/public-domain terms.

For MERDPOS the timestamp argument must be exactly `MMddyyyyHHmmss` and the device code exactly four digits. The backend currently accepts compact-v2 tokens only when the POS device and store are active, the public key is registered for that device, the Ed25519 signature verifies, and the embedded timestamp is within the backend freshness window.

Do not call `ResetDeviceKey` during normal operation. Generate/register the key once per POS and then keep using the same key.
