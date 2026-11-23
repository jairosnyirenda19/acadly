import { navigate, useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { copy } from '../copy'
import { Attention, DivisionChoice, Sheet } from '../programmes/parts'
import { PageHead, Tag } from '../settings/parts'
import { classroomApi, useClassroomDivision } from './api'

const t = copy.classroom

/** Classroom (D-104): the course pages of this semester the person runs, or sees. */
export function CoursesScreen() {
  const { division, choices, choose } = useClassroomDivision()
  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return <Loaded key={division.id} division={division.id} choice={<DivisionChoice choices={choices} division={division} choose={choose} />} />
}

function Loaded({ division, choice }: { division: string; choice: React.ReactNode }) {
  const loaded = useLoad(`classroom-courses:${division}`, () => classroomApi.courses(division))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={5} onRetry={loaded.reload} />
  const data = loaded.data

  return (
    <>
      <PageHead kicker={data.period?.name ?? t.kicker} title={t.title} intro={t.intro} actions={choice} />
      <div className="px-[clamp(16px,3vw,40px)] pb-8">
        <Sheet label={t.title}>
          {data.courses.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{t.none}</p>
          ) : (
            <ul>
              {data.courses.map((c) => (
                <li key={c.id} className="border-b border-rule last:border-b-0">
                  <a
                    href={`/classroom/c/${c.id}`}
                    onClick={(e) => (e.preventDefault(), navigate(`/classroom/c/${c.id}`))}
                    className="flex min-h-14 flex-wrap items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-paper"
                  >
                    <span className="flex min-w-0 flex-col">
                      <strong>{`${c.code} · ${c.title}${c.group !== 'A' ? ` · ${c.group}` : ''}`}</strong>
                      <span className="text-[14px] text-text-muted">{[c.lecturers.join(', '), t.students(c.students), t.tasksCount(c.tasks)].filter((x) => x !== '').join(' · ')}</span>
                    </span>
                    <span className="flex gap-2">
                      {c.teaches && <Tag tone="plain">{t.mine}</Tag>}
                      {c.to_mark > 0 && <Tag tone="attention">{t.toMark(c.to_mark)}</Tag>}
                    </span>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </Sheet>
      </div>
    </>
  )
}
