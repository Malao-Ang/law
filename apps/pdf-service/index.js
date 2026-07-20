const express = require('express');
const puppeteer = require('puppeteer');

const app = express();
app.use(express.json({ limit: '10mb' }));

app.post('/render', async (req, res) => {
  const html = typeof req.body?.html === 'string' ? req.body.html : '';

  if (!html) {
    res.status(422).json({ message: 'html is required' });
    return;
  }

  let browser;
  try {
    browser = await puppeteer.launch({
      headless: true,
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'networkidle0' });
    const pdf = await page.pdf({
      format: 'A4',
      margin: { top: '2.54cm', bottom: '2.54cm', left: '3.17cm', right: '3.17cm' },
      printBackground: true,
    });

    res.setHeader('Content-Type', 'application/pdf');
    res.send(pdf);
  } catch (error) {
    console.error(error);
    res.status(500).json({ message: 'PDF render failed' });
  } finally {
    if (browser) {
      await browser.close();
    }
  }
});

const port = process.env.PORT || 3001;
app.listen(port, () => {
  console.log(`pdf-service listening on ${port}`);
});
