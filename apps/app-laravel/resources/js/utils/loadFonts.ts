const FONTS: Array<{ family: string; url: string; weight: string; style: string }> = [
  { family: 'TH Sarabun New', url: '/fonts/THSarabunNew.ttf',          weight: 'normal', style: 'normal' },
  { family: 'TH Sarabun New', url: '/fonts/THSarabunNew-Bold.ttf',     weight: 'bold',   style: 'normal' },
  { family: 'TH Sarabun New', url: '/fonts/THSarabunNew-Italic.ttf',   weight: 'normal', style: 'italic' },
  { family: 'TH Sarabun New', url: '/fonts/THSarabunNew-BoldItalic.ttf', weight: 'bold', style: 'italic' },
  { family: 'TH Sarabun PSK', url: '/fonts/THSarabunPSK.ttf',          weight: 'normal', style: 'normal' },
  { family: 'TH Sarabun PSK', url: '/fonts/THSarabunPSK-Bold.ttf',     weight: 'bold',   style: 'normal' },
  { family: 'TH Sarabun PSK', url: '/fonts/THSarabunPSK-Italic.ttf',   weight: 'normal', style: 'italic' },
  { family: 'TH Sarabun PSK', url: '/fonts/THSarabunPSK-BoldItalic.ttf', weight: 'bold', style: 'italic' },
  { family: 'TH SarabunIT9',  url: '/fonts/THSarabunIT9.ttf',          weight: 'normal', style: 'normal' },
  { family: 'TH SarabunIT9',  url: '/fonts/THSarabunIT9-Bold.ttf',     weight: 'bold',   style: 'normal' },
  { family: 'TH SarabunIT9',  url: '/fonts/THSarabunIT9-Italic.ttf',   weight: 'normal', style: 'italic' },
  { family: 'TH SarabunIT9',  url: '/fonts/THSarabunIT9-BoldItalic.ttf', weight: 'bold', style: 'italic' },
];

export function loadThaiEditorFonts(): void {
  if (typeof FontFace === 'undefined') return;

  for (const { family, url, weight, style } of FONTS) {
    const face = new FontFace(family, `url(${url})`, { weight, style });
    face.load().then(loaded => document.fonts.add(loaded)).catch(() => {});
  }
}
