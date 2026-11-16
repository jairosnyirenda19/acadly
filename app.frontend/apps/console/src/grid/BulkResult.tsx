import { Button } from '@acadly/ui'
import { Tag } from '../settings/parts'

/**
 * What a bulk action came to (the approved "After a bulk action" design): one
 * line for what was done, then each row that was not, with the reason.
 */
export function BulkResult({
  label,
  done,
  notDoneCount,
  notDone,
  line,
  refused,
  close,
  onClose,
}: {
  label: string
  /** "38 learners moved to Form 2 West." */
  done: string
  /** "2 were not moved:" */
  notDoneCount: string
  /** The red word on each row left out: "Not moved". */
  notDone: string
  /** One row left out: "Ivy Moyo: left the school on 3 November." */
  line: (name: string, reason: string) => string
  refused: { id: string; name: string; reason: string }[]
  close: string
  onClose: () => void
}) {
  return (
    <section role="status" aria-label={label} className="flex flex-wrap items-start gap-3 border-2 border-ink bg-sheet p-4">
      <div className="min-w-0 flex-[1_1_260px]">
        <p className="mb-1.5 text-[15px] font-extrabold">{done}</p>
        {refused.length > 0 && (
          <>
            <p className="mb-2 text-sm">{notDoneCount}</p>
            <ul className="text-sm">
              {refused.map((r) => (
                <li key={r.id} className="flex flex-wrap items-baseline gap-2 border-t border-rule py-1.5">
                  <Tag tone="wrong">{notDone}</Tag>
                  {line(r.name, r.reason)}
                </li>
              ))}
            </ul>
          </>
        )}
      </div>
      <Button variant="secondary" onClick={onClose}>
        {close}
      </Button>
    </section>
  )
}
