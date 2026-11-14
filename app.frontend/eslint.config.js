import js from '@eslint/js'
import reactHooks from 'eslint-plugin-react-hooks'
import globals from 'globals'
import tseslint from 'typescript-eslint'

/*
 * The frontend rules of docs/architecture/07-engineering-rules.md, section G.
 * Each rule below names the rule it guards.
 */
export default tseslint.config(
  { ignores: ['**/dist/**', '**/node_modules/**', 'packages/api-client/src/schema.d.ts'] },

  js.configs.recommended,
  ...tseslint.configs.strict,

  {
    files: ['**/*.{ts,tsx}'],
    languageOptions: { globals: globals.browser },
    plugins: { 'react-hooks': reactHooks },
    rules: {
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'error',
    },
  },

  {
    // G2 · the API is reached only through the generated client.
    files: ['apps/**/*.{ts,tsx}', 'packages/ui/**/*.{ts,tsx}', 'packages/kernel/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-globals': [
        'error',
        { name: 'fetch', message: 'Call the API through @acadly/kernel (the generated client). Rule G2.' },
        { name: 'XMLHttpRequest', message: 'Call the API through @acadly/kernel (the generated client). Rule G2.' },
      ],
      'no-restricted-imports': [
        'error',
        {
          paths: [{ name: 'openapi-fetch', message: 'Only @acadly/api-client may import openapi-fetch. Rule G2.' }],
          patterns: [
            { group: ['@acadly/*/src/*'], message: 'Import a package by its name, not by a path inside it.' },
            { group: ['**/modules/*/*/**'], message: "Import another module only through its index file. Rule G4." },
          ],
        },
      ],
    },
  },

  {
    // G6 · components use tokens. A raw hex colour belongs in tokens.css only.
    files: ['apps/**/*.tsx', 'packages/ui/**/*.tsx'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/#[0-9a-fA-F]{3,8}\\b/]',
          message: 'Use a design token (bg-ink, text-teal …), not a raw colour. Rule G6.',
        },
        {
          selector: 'TemplateElement[value.raw=/#[0-9a-fA-F]{3,8}\\b/]',
          message: 'Use a design token (bg-ink, text-teal …), not a raw colour. Rule G6.',
        },
      ],
    },
  },

  {
    files: ['**/*.test.ts', '*.config.{js,ts}', 'apps/*/vite.config.ts'],
    languageOptions: { globals: globals.node },
  },
)
