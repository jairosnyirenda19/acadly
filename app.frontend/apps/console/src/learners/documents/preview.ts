/**
 * A picture of the first page of a PDF, drawn in the browser when the file is
 * added (D-090). The server decodes it and draws it again before it keeps it.
 * The PDF library loads only when a PDF is chosen. Null when it cannot be drawn.
 */
export async function firstPagePicture(file: File): Promise<Blob | null> {
  if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) return null

  try {
    const [pdfjs, worker] = await Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')])
    pdfjs.GlobalWorkerOptions.workerSrc = worker.default
    const task = pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) })
    const doc = await task.promise

    try {
      const page = await doc.getPage(1)
      const base = page.getViewport({ scale: 1 })
      const viewport = page.getViewport({ scale: Math.min(2, 720 / base.width) })
      const canvas = document.createElement('canvas')
      canvas.width = Math.ceil(viewport.width)
      canvas.height = Math.ceil(viewport.height)
      const context = canvas.getContext('2d')
      if (context === null) return null
      context.fillStyle = '#FFFFFF'
      context.fillRect(0, 0, canvas.width, canvas.height)
      await page.render({ canvas, canvasContext: context, viewport }).promise

      return await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82))
    } finally {
      void task.destroy()
    }
  } catch {
    return null
  }
}
