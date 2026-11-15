import { Notice } from '@acadly/ui'
import { useRef, useState, type ChangeEvent } from 'react'
import { copy } from '../copy'
import { errorFor, setupApi, type InstitutionState } from './api'
import { Column, Columns, Input, ReadOnly, SectionTitle, Select, StepFooter, TextArea } from './parts'
import type { StepProps } from './types'

const t = copy.setup.steps.school
type FieldKey = 'name' | 'short_name' | 'code' | 'email' | 'phone' | 'website' | 'country' | 'district' | 'physical_address' | 'postal_address'

function initial(school: InstitutionState['school']): Record<FieldKey, string> {
  return {
    name: school.name,
    short_name: school.short_name ?? '',
    code: school.code ?? '',
    email: school.email ?? '',
    phone: school.phone ?? '',
    website: school.website ?? '',
    country: school.country ?? 'MW',
    district: school.district ?? '',
    physical_address: school.physical_address ?? '',
    postal_address: school.postal_address ?? '',
  }
}

export function SchoolStep({ institution, onInstitution, layout, next }: StepProps) {
  const [form, setForm] = useState(() => initial(institution.school))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState(false)
  const [logoError, setLogoError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const [logoVersion, setLogoVersion] = useState(0)
  const fileInput = useRef<HTMLInputElement>(null)

  const country = institution.countries.find((c) => c.code === form.country) ?? institution.countries[0]
  const set = (key: FieldKey) => (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    const value = key === 'code' ? event.target.value.toUpperCase() : event.target.value
    setForm((current) => ({ ...current, [key]: value }))
  }
  const err = (key: FieldKey) => errorFor(errors, key)

  const save = async () => {
    setWorking(true)
    setFailed(false)
    const result = await setupApi.saveSchool({ ...form, website: form.website || null, physical_address: form.physical_address || null, postal_address: form.postal_address || null })
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      onInstitution(result.data)
      next()
    } else if (result.kind === 'invalid') {
      setErrors(result.errors)
    } else {
      setFailed(true)
    }
  }

  const upload = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ''

    if (file === undefined) return

    setUploading(true)
    setLogoError(null)
    const result = await setupApi.uploadLogo(file)
    setUploading(false)

    if (result.kind === 'saved') {
      onInstitution(result.data)
      setLogoVersion(logoVersion + 1)
    } else {
      setLogoError(result.kind === 'invalid' ? (errorFor(result.errors, 'file') ?? copy.setup.fix) : copy.setup.loadFailed)
    }
  }

  return layout({
    title: t.title,
    intro: t.intro,
    footer: <StepFooter step="school" primary={copy.setup.continue} onPrimary={() => void save()} working={working} />,
    children: (
      <Columns>
        <Column wide sheet>
          {(failed || Object.keys(errors).length > 0) && (
            <div className="mb-5">
              <Notice tone="wrong" title={copy.setup.fixTitle}>
                {failed ? copy.setup.loadFailed : copy.setup.fix}
              </Notice>
            </div>
          )}
          <SectionTitle>{t.schoolSection}</SectionTitle>
          <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-x-6 gap-y-4.5">
            <Input label={t.nameLabel} hint={t.nameHint} required wide value={form.name} onChange={set('name')} error={err('name')} maxLength={120} />
            <Input label={t.shortLabel} hint={t.shortHint} required value={form.short_name} onChange={set('short_name')} error={err('short_name')} maxLength={16} />
            <Input
              label={t.codeLabel}
              hint={t.codeHint}
              required
              value={form.code}
              onChange={set('code')}
              error={err('code')}
              maxLength={6}
              readOnly={institution.school.open}
              className="ref"
            />
          </div>

          <div className="my-7 h-0.5 bg-ink" />
          <SectionTitle>{t.contactSection}</SectionTitle>
          <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-x-6 gap-y-4.5">
            <Input label={t.emailLabel} type="email" required value={form.email} onChange={set('email')} error={err('email')} />
            <Input label={t.phoneLabel} type="tel" required value={form.phone} onChange={set('phone')} error={err('phone')} />
            <Input label={t.websiteLabel} value={form.website} onChange={set('website')} error={err('website')} />
            <Select label={t.districtLabel} required value={form.district} onChange={set('district')} error={err('district')}>
              <option value="">{t.districtChoose}</option>
              {country?.districts.map((district) => (
                <option key={district} value={district}>
                  {district}
                </option>
              ))}
            </Select>
            <TextArea label={t.physicalLabel} value={form.physical_address} onChange={set('physical_address')} error={err('physical_address')} />
            <TextArea label={t.postalLabel} value={form.postal_address} onChange={set('postal_address')} error={err('postal_address')} />
            <Select label={t.countryLabel} required value={form.country} onChange={set('country')} error={err('country')}>
              {institution.countries.map((c) => (
                <option key={c.code} value={c.code}>
                  {c.name}
                </option>
              ))}
            </Select>
            <ReadOnly label={t.timezoneLabel} value={country?.timezone ?? ''} hint={t.timezoneHint} />
          </div>
        </Column>

        <Column edge>
          <SectionTitle>{t.logoSection}</SectionTitle>
          <div className="flex flex-wrap items-start gap-4">
            <div className="grid size-[152px] place-items-center border-2 border-ink bg-sheet">
              {institution.school.has_logo ? (
                <img src={`${import.meta.env.VITE_API_URL ?? '/api'}/v1/school/logo?v=${logoVersion}`} alt={t.logoSection} className="max-h-[132px] max-w-[132px]" />
              ) : (
                <span className="px-3 text-center text-[13px] text-text-muted">{t.logoNone}</span>
              )}
            </div>
            <div className="min-w-0 flex-[1_1_160px]">
              <input ref={fileInput} type="file" accept="image/png,image/jpeg" className="sr-only" tabIndex={-1} onChange={(event) => void upload(event)} />
              <button
                type="button"
                disabled={uploading}
                onClick={() => fileInput.current?.click()}
                className="h-11 border-2 border-ink px-[18px] text-[15px] font-extrabold hover:bg-field disabled:border-disabled disabled:bg-disabled disabled:text-text-muted"
              >
                {uploading ? t.logoUploading : institution.school.has_logo ? t.logoReplace : t.logoUpload}
              </button>
            </div>
          </div>
          <p className="mt-3 text-[13px] text-text-muted">{t.logoHint}</p>
          {logoError !== null && (
            <div className="mt-3">
              <Notice tone="wrong" title={t.logoFailedTitle}>
                {logoError}
              </Notice>
            </div>
          )}

          <div className="my-7 h-0.5 bg-ink" />
          <SectionTitle>{t.previewSection}</SectionTitle>
          <div className="label-caps mb-1.5 text-text-muted">{t.previewSignIn}</div>
          <div className="flex items-center gap-3 bg-ink p-3 text-sheet">
            <span aria-hidden="true" className="size-10 bg-marigold" />
            <span className="type-display text-[26px] leading-[0.9]">{form.name || institution.school.name}</span>
          </div>
          <div className="label-caps mt-4 mb-1.5 text-text-muted">{t.previewSms}</div>
          <div className="max-w-[320px] border-2 border-ink bg-sheet p-3 text-sm leading-[1.45]">{t.previewSmsText(form.short_name || form.name)}</div>
          <div className="mt-1.5 text-[13px] text-text-muted">{t.previewSmsNote}</div>
        </Column>
      </Columns>
    ),
  })
}
