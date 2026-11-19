import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { applyAppearance } from '@acadly/ui'
import { App } from './App'
import './app.css'

// Light or dark, before anything is drawn, so the page never flashes the other one.
applyAppearance()

const root = document.getElementById('root')

if (root === null) {
  throw new Error('The page has no #root element.')
}

createRoot(root).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
