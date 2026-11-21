/**
 * The attendance phone's passkey (D-102): the phone's own lock (fingerprint,
 * face or PIN) makes and uses a key that never leaves the phone. These turn
 * the browser's bytes into text the server reads, and back.
 */

const toText = (bytes: ArrayBuffer | Uint8Array): string => {
  const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes)
  let s = ''
  for (const b of view) s += String.fromCharCode(b)

  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

const toBytes = (text: string): Uint8Array<ArrayBuffer> => {
  const s = atob(text.replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(s.length))
  for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i)

  return out
}

/** Whether this browser can hold a passkey made with the phone's own lock. */
export async function canHoldPasskey(): Promise<boolean> {
  try {
    return typeof PublicKeyCredential !== 'undefined' && (await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable())
  } catch {
    return false
  }
}

/** Makes the attendance phone's passkey. Null when the student stopped or the phone cannot. */
export async function makePasskey(o: { challenge: string; rp_id: string; user_handle: string; name: string }, rpName: string): Promise<{ client_data_json: string; attestation_object: string } | null> {
  try {
    const credential = await navigator.credentials.create({
      publicKey: {
        challenge: toBytes(o.challenge),
        rp: { id: o.rp_id, name: rpName },
        user: { id: toBytes(o.user_handle), name: o.name, displayName: o.name },
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
        authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'discouraged' },
        attestation: 'none',
        timeout: 60000,
      },
    })
    if (!(credential instanceof PublicKeyCredential) || !(credential.response instanceof AuthenticatorAttestationResponse)) return null

    return { client_data_json: toText(credential.response.clientDataJSON), attestation_object: toText(credential.response.attestationObject) }
  } catch {
    return null
  }
}

/** Signs a room code with the attendance phone's passkey. Null when the student stopped. */
export async function signCode(code: string, credentialId: string, rpId: string): Promise<{ credential_id: string; client_data_json: string; authenticator_data: string; signature: string } | null> {
  try {
    const credential = await navigator.credentials.get({
      publicKey: {
        challenge: new TextEncoder().encode(code),
        rpId,
        allowCredentials: [{ type: 'public-key', id: toBytes(credentialId) }],
        userVerification: 'required',
        timeout: 60000,
      },
    })
    if (!(credential instanceof PublicKeyCredential) || !(credential.response instanceof AuthenticatorAssertionResponse)) return null

    return {
      credential_id: toText(credential.rawId),
      client_data_json: toText(credential.response.clientDataJSON),
      authenticator_data: toText(credential.response.authenticatorData),
      signature: toText(credential.response.signature),
    }
  } catch {
    return null
  }
}

/** The phone's place, once, for the campus check. Null when the student did not allow it. */
export function wherePhoneIs(): Promise<{ latitude: number; longitude: number; accuracy: number } | null> {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) return resolve(null)
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
    )
  })
}
