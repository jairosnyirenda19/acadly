import { copy } from '../copy'
import { Select } from '../setup/parts'
import { missingAt, placeKey, type Giving } from './api'

const t = copy.settings.give

/**
 * Role and place, for giving a role or inviting a person. When the viewer
 * does not hold every permission of the role at the place, it says which.
 */
export function RolePlace({
  giving,
  role,
  place,
  onRole,
  onPlace,
  errors,
}: {
  giving: Giving
  role: string
  place: string
  onRole: (id: string) => void
  onPlace: (key: string) => void
  errors: { role?: string | undefined; place?: string | undefined }
}) {
  const missing = role !== '' && place !== '' ? missingAt(giving, role, place) : []
  const roleName = giving.roles.find((r) => r.id === role)?.name ?? ''
  const placeName = giving.places.find((p) => placeKey(p) === place)?.label ?? ''

  return (
    <>
      <Select label={t.role} required value={role} onChange={(e) => onRole(e.target.value)} error={errors.role}>
        <option value="">{t.chooseRole}</option>
        {giving.roles.map((r) => (
          <option key={r.id} value={r.id}>
            {r.name}
          </option>
        ))}
      </Select>
      <Select label={t.where} required value={place} onChange={(e) => onPlace(e.target.value)} error={errors.place}>
        <option value="">{t.choosePlace}</option>
        {giving.places.map((p) => (
          <option key={placeKey(p)} value={placeKey(p)}>
            {p.label}
          </option>
        ))}
      </Select>
      {missing.length > 0 && (
        <div role="note" className="col-span-full border-l-4 border-rule-dashed bg-sheet px-3.5 py-2.5 text-[13px]">
          <strong>{t.notAvailable}</strong> {t.notAvailableText(roleName, missing.join(', '), placeName)}
        </div>
      )}
    </>
  )
}

/** The place in the request body. */
export function placeBody(key: string): { scope_type: string; scope_id: string | null } {
  const [type = '', id = ''] = key.split(':')

  return { scope_type: type, scope_id: id === '' ? null : id }
}
