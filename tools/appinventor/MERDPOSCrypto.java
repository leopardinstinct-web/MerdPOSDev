package com.merdpos.crypto;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import com.google.appinventor.components.runtime.AndroidNonvisibleComponent;
import com.google.appinventor.components.runtime.Component;
import com.google.appinventor.components.runtime.ComponentContainer;

import java.nio.charset.Charset;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.SecureRandom;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

import net.i2p.crypto.eddsa.EdDSAEngine;
import net.i2p.crypto.eddsa.EdDSAPrivateKey;
import net.i2p.crypto.eddsa.spec.EdDSANamedCurveTable;
import net.i2p.crypto.eddsa.spec.EdDSAParameterSpec;
import net.i2p.crypto.eddsa.spec.EdDSAPrivateKeySpec;
public final class MERDPOSCrypto extends AndroidNonvisibleComponent implements Component {
  private static final String PREFS = "merdpos_crypto_v1";
  private static final String KEY_ALIAS = "MERDPOSCryptoWrapV1";
  private static final String PREF_IV = "seed_iv";
  private static final String PREF_CT = "seed_ct";
  private static final String PREF_PUB = "public_key_b64";
  private static final Charset UTF8 = Charset.forName("UTF-8");
  private static final char[] HEX = "0123456789ABCDEF".toCharArray();

  private final Context context;
  private final SharedPreferences prefs;
  private final SecureRandom secureRandom = new SecureRandom();
  private String lastError = "";

  public MERDPOSCrypto(ComponentContainer container) {
    super(container.$form());
    this.context = container.$context();
    this.prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
  }

  public String GenerateNonceHex() {
    clearError();
    byte[] bytes = new byte[8];
    secureRandom.nextBytes(bytes);
    char[] out = new char[16];
    for (int i = 0; i < bytes.length; i++) {
      int value = bytes[i] & 0xFF;
      out[i * 2] = HEX[value >>> 4];
      out[i * 2 + 1] = HEX[value & 0x0F];
    }
    return new String(out);
  }

  public boolean HasDeviceKey() {
    clearError();
    if (!prefs.contains(PREF_IV) || !prefs.contains(PREF_CT) || !prefs.contains(PREF_PUB)) {
      return false;
    }
    try {
      byte[] seed = loadSeed();
      wipe(seed);
      return true;
    } catch (Exception e) {
      fail(e);
      return false;
    }
  }

  public String GenerateDeviceKey() {
    clearError();
    try {
      requireSecureStorage();
      if (prefs.contains(PREF_IV) && prefs.contains(PREF_CT) && prefs.contains(PREF_PUB)) {
        byte[] seed = loadSeed();
        wipe(seed);
        return prefs.getString(PREF_PUB, "");
      }

      byte[] seed = new byte[32];
      secureRandom.nextBytes(seed);
      EdDSAPrivateKey privateKey = privateKeyFromSeed(seed);
      String publicKey = Base64.encodeToString(privateKey.getAbyte(), Base64.NO_WRAP);
      storeSeed(seed, publicKey);
      wipe(seed);
      return publicKey;
    } catch (Exception e) {
      fail(e);
      return "";
    }
  }

  public String GetPublicKeyBase64() {
    clearError();
    try {
      if (!prefs.contains(PREF_PUB)) return GenerateDeviceKey();
      byte[] seed = loadSeed();
      wipe(seed);
      return prefs.getString(PREF_PUB, "");
    } catch (Exception e) {
      fail(e);
      return "";
    }
  }

  public String Sign(String message) {
    clearError();
    if (message == null) message = "";
    byte[] seed = null;
    try {
      seed = loadSeed();
      EdDSAPrivateKey privateKey = privateKeyFromSeed(seed);
      EdDSAEngine signer = new EdDSAEngine(MessageDigest.getInstance("SHA-512"));
      signer.initSign(privateKey);
      signer.update(message.getBytes(UTF8));
      byte[] signature = signer.sign();
      return Base64.encodeToString(signature, Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
    } catch (Exception e) {
      fail(e);
      return "";
    } finally {
      wipe(seed);
    }
  }

  public String CreateAttendanceToken(String deviceCode, String timestamp) {
    clearError();
    if (deviceCode == null || !deviceCode.matches("^[0-9]{4}$")) {
      lastError = "Device code must be exactly four digits.";
      return "";
    }
    if (timestamp == null || !timestamp.matches("^[0-9]{14}$")) {
      lastError = "Timestamp must be exactly 14 digits in MMddyyyyHHmmss format.";
      return "";
    }
    if (!HasDeviceKey()) {
      String publicKey = GenerateDeviceKey();
      if (publicKey.length() == 0) return "";
    }

    String payload = deviceCode + timestamp + GenerateNonceHex();
    String signature = Sign(payload);
    if (signature.length() == 0) return "";
    return payload + "." + signature;
  }

  public boolean ResetDeviceKey() {
    clearError();
    try {
      prefs.edit().clear().commit();
      if (Build.VERSION.SDK_INT >= 23) {
        KeyStore keyStore = KeyStore.getInstance("AndroidKeyStore");
        keyStore.load(null);
        if (keyStore.containsAlias(KEY_ALIAS)) keyStore.deleteEntry(KEY_ALIAS);
      }
      return true;
    } catch (Exception e) {
      fail(e);
      return false;
    }
  }

  public String GetLastError() {
    return lastError;
  }

  private void requireSecureStorage() {
    if (Build.VERSION.SDK_INT < 23) {
      throw new IllegalStateException("MERDPOSCrypto requires Android 6.0 (API 23) or newer.");
    }
  }

  private EdDSAPrivateKey privateKeyFromSeed(byte[] seed) {
    EdDSAParameterSpec params = EdDSANamedCurveTable.getByName(EdDSANamedCurveTable.ED_25519);
    EdDSAPrivateKeySpec spec = new EdDSAPrivateKeySpec(seed, params);
    return new EdDSAPrivateKey(spec);
  }

  private SecretKey getOrCreateWrapKey() throws Exception {
    requireSecureStorage();
    KeyStore keyStore = KeyStore.getInstance("AndroidKeyStore");
    keyStore.load(null);
    if (!keyStore.containsAlias(KEY_ALIAS)) {
      KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
      KeyGenParameterSpec spec = new KeyGenParameterSpec.Builder(
          KEY_ALIAS,
          KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
          .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
          .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
          .setRandomizedEncryptionRequired(true)
          .build();
      generator.init(spec);
      generator.generateKey();
      keyStore.load(null);
    }
    return (SecretKey) keyStore.getKey(KEY_ALIAS, null);
  }

  private void storeSeed(byte[] seed, String publicKey) throws Exception {
    Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
    cipher.init(Cipher.ENCRYPT_MODE, getOrCreateWrapKey());
    byte[] iv = cipher.getIV();
    byte[] encrypted = cipher.doFinal(seed);
    boolean ok = prefs.edit()
        .putString(PREF_IV, Base64.encodeToString(iv, Base64.NO_WRAP))
        .putString(PREF_CT, Base64.encodeToString(encrypted, Base64.NO_WRAP))
        .putString(PREF_PUB, publicKey)
        .commit();
    if (!ok) throw new IllegalStateException("Could not persist the POS signing key.");
  }

  private byte[] loadSeed() throws Exception {
    requireSecureStorage();
    String ivText = prefs.getString(PREF_IV, "");
    String cipherText = prefs.getString(PREF_CT, "");
    if (ivText.length() == 0 || cipherText.length() == 0) {
      throw new IllegalStateException("No POS signing key exists. Generate the device key first.");
    }
    byte[] iv = Base64.decode(ivText, Base64.DEFAULT);
    byte[] encrypted = Base64.decode(cipherText, Base64.DEFAULT);
    Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
    cipher.init(Cipher.DECRYPT_MODE, getOrCreateWrapKey(), new GCMParameterSpec(128, iv));
    byte[] seed = cipher.doFinal(encrypted);
    if (seed.length != 32) throw new IllegalStateException("Stored POS signing key is invalid.");
    return seed;
  }

  private void clearError() {
    lastError = "";
  }

  private void fail(Exception e) {
    String message = e.getMessage();
    lastError = message == null || message.trim().length() == 0
        ? e.getClass().getSimpleName()
        : message.trim();
  }

  private void wipe(byte[] bytes) {
    if (bytes == null) return;
    for (int i = 0; i < bytes.length; i++) bytes[i] = 0;
  }
}
