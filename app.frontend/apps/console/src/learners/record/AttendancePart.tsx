import { useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { copy } from '../../copy'
import { Glyph } from '../../registers/parts'
import { Tag } from '../../settings/parts'
import { learnersApi, type LearnerAttendance, type LearnerState } from '../api'
import { Caption, Column, Columns, InLink, LedgerFrame, MarginCell, SectionTitle, tabHref, Td, Th } from './ui'

const t = copy.learners.record
const m = copy.messages
const r = copy.registers

type Week = LearnerAttendance['weeks'][number]
type Day = Week['days'][number]

const channelWord = (channel: string) => (channel === 'sms' ? m.sms : channel === 'email' ? m.email : channel)

/** The attendance part, loaded once and shared by the overview and the tab. */
function useAttendance(id: string) {
  return useLoad(`learner:attendance:${id}`, () => learnersApi.attendance(id))
}

/** One day as a mark cell: the glyph; a dashed box for a day to come; a quiet cell when there is no school. */
function DayCell({ day, size }: { day: Day; size: string }) {
  if (!day.school_day) return <span role="img" aria-label={`${t.week(day.day)}: ${t.noSchool}`} className={`inline-block shrink-0 bg-paper ${size}`} />
  if (day.future) return <span role="img" aria-label={`${t.week(day.day)}: ${t.dayToCome}`} className={`inline-block shrink-0 border-2 border-dashed border-rule-dashed bg-sheet ${size}`} />

  if (day.mark === null) return <span role="img" aria-label={`${t.week(day.day)}: ${r.notMarked}`} className={`inline-block shrink-0 border-2 border-rule bg-sheet ${size}`} />

  return <Glyph mark={day.mark} size={`${size} text-[13px]`} />
}

function WeekRow({ week, size = 'size-[26px]' }: { week: Week; size?: string }) {
  return (
    <div className="grid grid-cols-[64px_repeat(5,30px)] items-center gap-1">
      <span className="ref text-xs text-text-muted">{t.week(week.week)}</span>
      {week.days.slice(0, 5).map((day) => (
        <DayCell key={day.day} day={day} size={size} />
      ))}
    </div>
  )
}

/** The overview's attendance block: the last five weeks of mornings and today's lessons. */
export function AttendanceSummary({ learner }: { learner: LearnerState }) {
  const loaded = useAttendance(learner.id)
  const a = loaded.data

  return (
    <Column edge grow="flex-[1.3_1_340px]" label={learner.parts.find((p) => p.key === 'attendance')?.title}>
      <SectionTitle>{learner.parts.find((p) => p.key === 'attendance')?.title}</SectionTitle>
      {a === undefined ? (
        <Waiting phase={loaded.phase} what={t.lastWeeks} labels={copy.loading} rows={3} onRetry={loaded.reload} />
      ) : a.term === null ? (
        <p className="text-sm text-text-muted">{t.noTerm}</p>
      ) : (
        <>
          <Caption>{t.lastWeeks}</Caption>
          <div className="flex flex-col gap-1">
            {a.weeks
              .filter((w) => w.week <= a.today)
              .slice(-5)
              .map((w) => (
                <WeekRow key={w.week} week={w} />
              ))}
          </div>
          {a.takes_lessons && (
          <div className="mt-4">
            <Caption>{t.todayLessons}</Caption>
            {a.lessons_today.length === 0 ? (
              <p className="text-sm text-text-muted">{t.noLessonsToday}</p>
            ) : (
              <ul>
                {a.lessons_today.map((l) => (
                  <li key={l.period} className="grid grid-cols-[36px_52px_1fr_auto] items-center gap-2 border-b border-rule py-1.5 text-sm">
                    <span className="ref text-xs text-text-muted">{t.period(l.period)}</span>
                    <span className="ref text-xs">{l.starts}</span>
                    <span className="min-w-0 truncate font-bold">{l.kind === 'activity' ? l.name : l.subject}</span>
                    <Glyph mark={l.mark} size="size-[26px] text-[13px]" />
                  </li>
                ))}
              </ul>
            )}
          </div>
          )}
          <InLink href={tabHref(learner.id, 'attendance')}>{t.seeAttendance}</InLink>
        </>
      )}
    </Column>
  )
}

const correctionTone: Record<string, 'done' | 'attention' | 'wrong' | 'plain'> = { approved: 'done', requested: 'attention', declined: 'wrong', drafted: 'plain' }

/** The attendance tab: the term as a calendar, lessons by subject, absences and corrections. */
export function AttendanceTab({ learner }: { learner: LearnerState }) {
  const loaded = useAttendance(learner.id)
  const a = loaded.data

  if (a === undefined) return <Waiting phase={loaded.phase} what={t.lastWeeks} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  if (a.term === null) {
    return (
      <Columns top>
        <Column>
          <p className="text-sm text-text-muted">{t.noTerm}</p>
        </Column>
      </Columns>
    )
  }

  const legend = ['present', 'absent', 'late', 'excused'] as const

  return (
    <>
      <Columns top>
        <Column grow="flex-[1.2_1_340px]" label={a.term.name}>
          <SectionTitle>{a.term.name}</SectionTitle>
          <p className="mb-3 max-w-[60ch] text-[13px] text-text-muted">{t.calendarNote}</p>
          <div className="flex flex-col gap-1">
            <div aria-hidden="true" className="label-caps grid grid-cols-[64px_repeat(5,30px)] gap-1 text-text-muted">
              <span>{t.weekOf}</span>
              {t.weekdays.map((d) => (
                <span key={d} className="text-center">
                  {d}
                </span>
              ))}
            </div>
            {a.weeks.map((w) => (
              <WeekRow key={w.week} week={w} />
            ))}
          </div>
          <ul aria-label={t.legendLabel} className="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-[13px]">
            {legend.map((mark) => (
              <li key={mark} className="inline-flex items-center gap-1.5">
                <span aria-hidden="true">
                  <Glyph mark={mark} size="size-5 text-[11px]" />
                </span>
                {r.marks[mark]}
              </li>
            ))}
            <li className="inline-flex items-center gap-1.5">
              <span aria-hidden="true" className="size-5 border-2 border-rule bg-sheet" />
              {r.notMarked}
            </li>
            <li className="inline-flex items-center gap-1.5">
              <span aria-hidden="true" className="size-5 border-2 border-dashed border-rule-dashed bg-sheet" />
              {t.dayToCome}
            </li>
            <li className="inline-flex items-center gap-1.5">
              <span aria-hidden="true" className="size-5 bg-paper" />
              {t.noSchool}
            </li>
          </ul>
        </Column>
        {a.takes_lessons && (
        <Column edge label={t.subjectsTitle}>
          <SectionTitle>{t.subjectsTitle}</SectionTitle>
          <p className="mb-3 text-[13px] text-text-muted">{t.subjectsNote}</p>
          {a.subjects.length === 0 && <p className="text-sm text-text-muted">{t.noSubjects}</p>}
          <ul>
            {a.subjects.map((sub) => {
              const full = sub.total > 0 && sub.present === sub.total
              const share = sub.total > 0 ? Math.round((sub.present / sub.total) * 100) : 0

              return (
                <li key={sub.subject} className="border-b border-rule py-2">
                  <div className="flex items-baseline gap-2 text-sm">
                    <span className="flex-1 font-bold">{sub.subject}</span>
                    <span className="ref text-[13px]">{t.subjectShare(sub.present, sub.total)}</span>
                  </div>
                  <span aria-hidden="true" className="mt-1.5 block h-1.5 bg-field">
                    <span className={`block h-full ${full ? 'bg-teal' : 'bg-ink'}`} style={{ width: `${share}%` }} />
                  </span>
                </li>
              )
            })}
          </ul>
        </Column>
        )}
      </Columns>

      <Columns top>
        <Column label={t.absencesTitle}>
          <SectionTitle>{t.absencesTitle}</SectionTitle>
          {a.absences.length === 0 ? (
            <p className="text-sm text-text-muted">{t.noAbsences}</p>
          ) : (
            <LedgerFrame label={t.absencesTitle}>
              <table className="w-full min-w-[760px] border-collapse">
                <thead>
                  <tr>
                    <Th>{t.colDay}</Th>
                    <Th>{t.colRegister}</Th>
                    <Th>{t.colMark}</Th>
                    <Th>{t.colReason}</Th>
                    <Th>{t.colFamily}</Th>
                  </tr>
                </thead>
                <tbody>
                  {a.absences.map((x, i) => (
                    <tr key={`${x.register_id}-${i}`} className={x.what === 'missed' ? 'bg-marigold-wash' : ''}>
                      <MarginCell>{t.day(x.day)}</MarginCell>
                      <Td>{x.lesson ?? t.morning}</Td>
                      <Td>{x.what === 'absent' ? <Tag tone="wrong">{t.absent}</Tag> : x.what === 'excused' ? <Tag tone="done">✓ {t.excused}</Tag> : <Tag tone="attention">{t.missed}</Tag>}</Td>
                      <Td wide>
                        {x.from_family !== null ? (
                          <>
                            <span className="block font-bold">{copy.registers.absenceReasons[x.from_family.kind] ?? x.from_family.kind}</span>
                            {x.from_family.note !== null && <span className="block">“{x.from_family.note}”</span>}
                            <span className="block text-[13px] text-text-muted">{t.familyGave(x.from_family.by, t.time(x.from_family.at))}</span>
                          </>
                        ) : (
                          (x.reason ?? <span className="text-text-muted">{t.noReason}</span>)
                        )}
                      </Td>
                      <Td wide>
                        {x.told.length === 0 && x.follow_ups.length === 0 && <span className="text-text-muted">{t.notTold}</span>}
                        {x.told.map((told, j) => (
                          <span key={j} className="block text-[13px]">
                            {t.toldLine(channelWord(told.channel), told.to, (m.state[told.state] ?? told.state).toLowerCase(), t.time(told.at))}
                          </span>
                        ))}
                        {x.follow_ups.map((f, j) => (
                          <span key={j} className="block text-[13px] text-text-muted">
                            {t.followUp[f.kind]?.(f.by, t.time(f.at)) ?? f.kind}
                            {f.note !== null && ` ${f.note}`}
                          </span>
                        ))}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </LedgerFrame>
          )}
        </Column>
      </Columns>

      <Columns top>
        <Column label={t.correctionsTitle}>
          <SectionTitle>{t.correctionsTitle}</SectionTitle>
          {a.corrections.length === 0 ? (
            <p className="text-sm text-text-muted">{t.noCorrections}</p>
          ) : (
            <LedgerFrame label={t.correctionsTitle}>
              <table className="w-full min-w-[760px] border-collapse">
                <thead>
                  <tr>
                    <Th>{t.colDay}</Th>
                    <Th>{t.colRegister}</Th>
                    <Th>{t.colChange}</Th>
                    <Th>{t.colReason}</Th>
                    <Th>{t.colAskedBy}</Th>
                    <Th>{t.colState}</Th>
                  </tr>
                </thead>
                <tbody>
                  {a.corrections.map((c, i) => (
                    <tr key={i}>
                      <MarginCell>{t.day(c.day)}</MarginCell>
                      <Td>{c.register === 'morning' ? t.morning : c.register}</Td>
                      <Td>
                        <span className="inline-flex items-center gap-1.5">
                          <Glyph mark={c.from} size="size-[26px] text-[13px]" />
                          <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="square">
                            <path d="M5 12h14" />
                            <path d="M13 6l6 6-6 6" />
                          </svg>
                          <Glyph mark={c.to} size="size-[26px] text-[13px]" />
                        </span>
                      </Td>
                      <Td wide>{c.reason}</Td>
                      <Td>{c.asked_by}</Td>
                      <Td>
                        <Tag tone={correctionTone[c.state] ?? 'plain'}>{t.correctionStates[c.state] ?? c.state}</Tag>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </LedgerFrame>
          )}
        </Column>
      </Columns>
    </>
  )
}
