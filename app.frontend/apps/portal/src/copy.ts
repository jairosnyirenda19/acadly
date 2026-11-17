/**
 * Every word the parent portal shows, in English and in Chichewa. Plain
 * words, short sentences: many parents read slowly or in a second language
 * (PRODUCT.md). Nothing about how the product is built.
 *
 * The Chichewa wording is a draft. A speaker at the school checks it before
 * the portal opens to families (D-082).
 */
const en = {
  product: 'Acadly IQ-X',
  kicker: 'For parents and guardians',
  english: 'English',
  chichewa: 'Chichewa',
  language: 'Language',
  loading: 'Loading',
  back: 'Back',
  retry: 'Try again',
  unavailableTitle: 'Service unavailable',
  unavailable: 'The school cannot be reached. Check the phone has a connection, then try again.',

  signIn: {
    title: 'Welcome',
    phone: 'Your phone number',
    phoneHint: 'The number the school has for you.',
    sendCode: 'Send me a code',
    sending: 'Sending',
    code: 'The code we sent you',
    codeHint: 'It came by text message. It works for 10 minutes.',
    again: 'Send it again',
    otherNumber: 'Use another number',
    open: 'Open',
    opening: 'Opening',
    noPassword: 'No password. This phone stays signed in for 90 days.',
    invalidPhoneTitle: 'Phone number not recognised',
    invalidPhone: 'Enter a phone number, such as 0991 234 567.',
    wrongCodeTitle: 'Code not accepted',
    wrongCode: 'The code is wrong or too old. Check the text message, or ask for a new code.',
    pausedTitle: 'Too many tries',
    paused: 'Wait ten minutes, then try again.',
    noCode: 'No message? The school may not have this number. Call the school office.',
  },

  confirm: {
    title: 'Your children',
    intro: 'The school has your number for these children. Are they yours?',
    mine: 'My child',
    notMine: 'Not my child',
    oneIsNot: 'One of them is not my child',
    oneIsNotNote: 'Tap a child to mark them. The school is told, and checks it. You will not see that child.',
    yes: 'Yes, these are my children',
    save: 'Save my answer',
    saving: 'Saving',
    failedTitle: 'Answer not saved',
    failed: 'Try again in a moment.',
  },

  home: {
    title: 'Your children',
    none: 'No children to show',
    noneNote: 'The school has not linked a child to this number yet. Call the school office.',
    suspended: 'Access paused by the school. Call the school office.',
    signOut: 'Sign out of this phone',
    today: { present: 'At school today', late: 'Late today', absent: 'Absent today', excused: 'Excused today' } as Record<string, string>,
    card: (term: string) => `${term} report card`,
    newCard: (term: string) => `New: ${term} report card`,
  },

  child: {
    card: 'Report card',
    attendance: 'Attendance',
    messages: 'Messages',
    call: 'Call the school',
    noCard: 'Not published yet',
    newCard: (term: string) => `${term} · new`,
    seeTerm: 'This term',
    seeMessages: 'From the school',
  },

  card: {
    kicker: (term: string) => `Report card · ${term}`,
    none: 'No report card yet',
    noneNote: 'The school sends a text message when the report card is ready.',
    position: 'Position',
    of: (n: number) => `of ${n}`,
    shared: 'shared',
    total: 'Total',
    subjects: 'Subjects',
    notReady: 'Not ready',
    classTeacher: 'Class teacher',
    head: 'Head teacher',
    reference: (ref: string) => `Reference ${ref}`,
    save: 'Save as PDF',
  },

  attendance: {
    kicker: (term: string) => `Attendance · ${term}`,
    present: 'Present',
    ofDays: (n: number) => `of ${n} days`,
    absent: 'Absent',
    late: 'Late',
    days: 'Days',
    lastAbsent: (day: string) => `Last absent on ${day}.`,
    none: 'No registers yet this term.',
    week: (day: string) => `Week of ${day}`,
    weekdays: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
    marks: { present: 'Present', late: 'Late', absent: 'Absent', excused: 'Excused' } as Record<string, string>,
    noSchool: 'No school',
    notTaken: 'Not taken',
    key: 'Key',
  },

  messages: {
    kicker: 'Messages',
    none: 'No messages yet',
    noneNote: 'Messages the school sends to this phone about this child show here.',
  },
}

export type Words = typeof en

const ny: Words = {
  product: 'Acadly IQ-X',
  kicker: 'Kwa makolo ndi osamalira ana',
  english: 'English',
  chichewa: 'Chichewa',
  language: 'Chilankhulo',
  loading: 'Dikirani',
  back: 'Bwererani',
  retry: 'Yesaninso',
  unavailableTitle: 'Sizikupezeka',
  unavailable: 'Sukulu sikupezeka pakadali pano. Onetsetsani kuti foni ili ndi intaneti, kenako yesaninso.',

  signIn: {
    title: 'Takulandirani',
    phone: 'Nambala yanu ya foni',
    phoneHint: 'Nambala imene sukulu ili nayo.',
    sendCode: 'Nditumizireni nambala',
    sending: 'Tikutumiza',
    code: 'Nambala imene takutumizirani',
    codeHint: 'Yabwera ngati uthenga (SMS). Igwira ntchito kwa mphindi 10.',
    again: 'Tumizaninso',
    otherNumber: 'Gwiritsani nambala ina',
    open: 'Lowani',
    opening: 'Tikutsegula',
    noPassword: 'Palibe mawu achinsinsi. Foni iyi idzakhala yolowa kwa masiku 90.',
    invalidPhoneTitle: 'Nambala sikudziwika',
    invalidPhone: 'Lembani nambala ya foni, monga 0991 234 567.',
    wrongCodeTitle: 'Nambala sinavomerezedwe',
    wrongCode: 'Nambala ndi yolakwika kapena yatha nthawi. Onani uthenga, kapena pemphani nambala yatsopano.',
    pausedTitle: 'Mwayesa kwambiri',
    paused: 'Dikirani mphindi khumi, kenako yesaninso.',
    noCode: 'Palibe uthenga? Mwina sukulu ilibe nambala iyi. Imbirani ofesi ya sukulu.',
  },

  confirm: {
    title: 'Ana anu',
    intro: 'Sukulu ili ndi nambala yanu ya ana awa. Kodi ndi anu?',
    mine: 'Mwana wanga',
    notMine: 'Si mwana wanga',
    oneIsNot: 'Mmodzi wa iwo si mwana wanga',
    oneIsNotNote: 'Dinani mwanayo kuti mumusankhe. Sukulu idzadziwitsidwa ndi kufufuza. Simudzaona mwanayo.',
    yes: 'Inde, awa ndi ana anga',
    save: 'Sungani yankho langa',
    saving: 'Tikusunga',
    failedTitle: 'Yankho silinasungidwe',
    failed: 'Yesaninso pakapita kanthawi.',
  },

  home: {
    title: 'Ana anu',
    none: 'Palibe ana oti tionetse',
    noneNote: 'Sukulu sinalumikize mwana ku nambala iyi. Imbirani ofesi ya sukulu.',
    suspended: 'Sukulu yayimitsa kaye. Imbirani ofesi ya sukulu.',
    signOut: 'Tulukani pa foni iyi',
    today: { present: 'Ali ku sukulu lero', late: 'Wachedwa lero', absent: 'Sanabwere lero', excused: 'Waloledwa lero' },
    card: (term: string) => `Lipoti la ${term}`,
    newCard: (term: string) => `Chatsopano: lipoti la ${term}`,
  },

  child: {
    card: 'Lipoti',
    attendance: 'Kupezeka ku sukulu',
    messages: 'Mauthenga',
    call: 'Imbirani sukulu',
    noCard: 'Silinatulutsidwe',
    newCard: (term: string) => `${term} · chatsopano`,
    seeTerm: 'Teremu ino',
    seeMessages: 'Kuchokera ku sukulu',
  },

  card: {
    kicker: (term: string) => `Lipoti · ${term}`,
    none: 'Palibe lipoti pano',
    noneNote: 'Sukulu idzatumiza uthenga lipoti likakonzeka.',
    position: 'Malo',
    of: (n: number) => `mwa ${n}`,
    shared: 'ndi ena',
    total: 'Zonse',
    subjects: 'Maphunziro',
    notReady: 'Silinakonzeke',
    classTeacher: 'Aphunzitsi a kalasi',
    head: 'Mphunzitsi wamkulu',
    reference: (ref: string) => `Nambala ${ref}`,
    save: 'Sungani ngati PDF',
  },

  attendance: {
    kicker: (term: string) => `Kupezeka · ${term}`,
    present: 'Analipo',
    ofDays: (n: number) => `mwa masiku ${n}`,
    absent: 'Sanabwere',
    late: 'Anachedwa',
    days: 'Masiku',
    lastAbsent: (day: string) => `Sanabwere komaliza pa ${day}.`,
    none: 'Palibe zolembedwa teremu ino.',
    week: (day: string) => `Sabata ya ${day}`,
    weekdays: ['Lol', 'Lach', 'Lachit', 'Lachin', 'Lachis'],
    marks: { present: 'Analipo', late: 'Anachedwa', absent: 'Sanabwere', excused: 'Waloledwa' },
    noSchool: 'Palibe sukulu',
    notTaken: 'Sizinalembedwe',
    key: 'Tanthauzo',
  },

  messages: {
    kicker: 'Mauthenga',
    none: 'Palibe mauthenga pano',
    noneNote: 'Mauthenga amene sukulu itumiza ku foni iyi okhudza mwanayu adzaonekera pano.',
  },
}

export type Language = 'en' | 'ny'

export const words: Record<Language, Words> = { en, ny }
