import { getPortalAddress, signOut } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { Heading } from '../sign-in/AuthLayout'

const t = copy.passwordNeeded

/** The console after a sign-in by code by SMS (the approved design, D-087): the password is needed; the parent portal is one tap away. */
export function PasswordNeeded({ onSignedOut }: { onSignedOut: () => void }) {
  const [portal, setPortal] = useState<string | null>(null)

  useEffect(() => {
    let live = true
    void getPortalAddress().then((found) => {
      if (live) setPortal(found)
    })

    return () => {
      live = false
    }
  }, [])

  return (
    <>
      <Heading kicker={t.kicker} title={t.title} />
      <div className="border-2 border-l-[6px] border-ink border-l-marigold bg-sheet px-4 py-3.5">
        <p className="mb-1.5 text-base leading-normal font-bold">{t.lead}</p>
        <p className="text-[15px] leading-normal">{t.text}</p>
      </div>
      <Button className="h-12" onClick={() => void signOut().then(onSignedOut)}>
        {t.signIn}
      </Button>
      {portal !== null && (
        <a href={portal} className="flex h-12 items-center justify-center border-2 border-ink text-base font-extrabold text-ink no-underline">
          {t.portal}
        </a>
      )}
    </>
  )
}
