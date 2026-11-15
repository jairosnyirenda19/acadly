/**
 * Every word the portal's sign-in screens show, in one place. Professional,
 * clean terms; nothing about how the product is built (PRODUCT.md).
 */
export const copy = {
  product: 'Acadly IQ-X',
  kicker: 'Parent portal',
  schoolFallback: 'Parent portal',
  email: 'Email address',
  password: 'Password',
  show: 'Show',
  hide: 'Hide',
  noAccount: 'No account? The school office registers parents and guardians.',

  signIn: {
    title: 'Sign in',
    submit: 'Sign in',
    working: 'Signing in',
    forgot: 'Forgot password',
    incorrectTitle: 'Sign-in failed',
    incorrect: 'The email address or password is incorrect.',
    pausedTitle: 'Sign-in is paused',
    paused: 'Too many failed attempts. Try again in one minute, or reset the password.',
    unavailableTitle: 'Service unavailable',
    unavailable: 'The service cannot be reached. Try again shortly.',
  },

  forgot: {
    back: 'Back to sign in',
    title: 'Reset password',
    intro: 'Enter the email address of the account. A reset link is sent to that address. The link expires in 60 minutes.',
    submit: 'Send reset link',
    working: 'Sending',
    sentTitle: 'Request received',
    sent: 'If an account exists for this email address, a reset link has been sent.',
    again: 'Use a different email address',
  },

  home: {
    signedInAs: 'Signed in as',
    pending: 'The portal home screen is not available yet.',
    signOut: 'Sign out',
  },
} as const
