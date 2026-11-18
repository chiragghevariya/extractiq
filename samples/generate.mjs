#!/usr/bin/env node
/**
 * ExtractIQ sample generator — fictional vendors, fictional buyer, no real data.
 *
 *   node generate.mjs                    # 30 invoices, 10 purchase orders, 10 goods received notes
 *   node generate.mjs --date 2026-11-14
 *
 * Variety on purpose: four invoice layouts (classic, modern two-column, compact, German),
 * one multi-page invoice, two scans (one skewed) as image-only PDFs, prices in 1,234.56 and
 * 1.234,56. Writes ./pdf/{scenarios,bulk,scans}/, ./manifest.json (expected outcome per file,
 * in ingestion order), ./erp/seed.json (the demo ERP) and ./csv/ (a master-data export).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import PDFDocument from 'pdfkit';
import { execFileSync } from 'node:child_process';

const here = path.dirname(fileURLToPath(import.meta.url));
const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => (a.startsWith('--') ? [...acc, [a.slice(2), all[i + 1]]] : acc), []));
const BASE = new Date(`${args.date ?? '2026-11-14'}T00:00:00Z`);

// Deterministic PRNG so regenerated files are byte-stable apart from PDF metadata.
let seed = 20261114;
const rand = () => ((seed = (seed * 1664525 + 1013904223) % 4294967296) / 4294967296);
const int = (min, max) => min + Math.floor(rand() * (max - min + 1));

const BUYER = { name: 'Demo Manufacturing Co.', address: ['100 Example Way', 'Springfield, ST 00000'] };
const VENDORS = {
  kestrel: { name: 'Kestrel Industrial Parts LLC', tax: '88-1234567', currency: 'USD', prefix: 'INV-K-', layout: 'classic', address: ['42 Foundry Road', 'Riverton, ST 00001'] },
  brightline: { name: 'Brightline Office Supply Co.', tax: '77-7654321', currency: 'USD', prefix: 'INV-BL-', layout: 'modern', address: ['9 Paper Mill Lane', 'Lakeside, ST 00002'] },
  harbor: { name: 'Harbor & Pine Packaging Ltd', tax: 'DE123456789', currency: 'EUR', prefix: 'HP-', layout: 'classic', address: ['Hafenstrasse 12', '00000 Musterstadt'] },
  nimbus: { name: 'Nimbus Cloud Services Inc', tax: '66-1112223', currency: 'USD', prefix: 'NCS-', layout: 'compact', address: ['1 Data Center Blvd', 'Hilltop, ST 00003'] },
  quill: { name: 'Quillfeather Stationers', tax: null, currency: 'USD', prefix: 'QF-', layout: 'classic', address: ['3 Ink Street', 'Oldtown, ST 00004'] },
};
// Kestrel's fastener range (KIP-5xxx) is what makes the 40-line multi-page invoice realistic.
const FASTENERS = ['M6', 'M8', 'M10', 'M12', 'M16'].flatMap((size, si) => [
  [`Hex bolt ${size} x 30, zinc`, 0.28 + si * 0.14], [`Hex bolt ${size} x 60, zinc`, 0.41 + si * 0.19], [`Socket cap screw ${size} x 25`, 0.36 + si * 0.17],
  [`Nyloc nut ${size}`, 0.09 + si * 0.05], [`Flat washer ${size}, form A`, 0.03 + si * 0.02], [`Spring washer ${size}`, 0.04 + si * 0.02],
  [`Threaded rod ${size} x 1m`, 1.6 + si * 0.9],
]).map(([desc, price], i) => [`KIP-${5001 + i}`, desc, Math.round(price * 100) / 100]);
const CATALOG = {
  kestrel: [['KIP-1001', 'Hex bolt M8 x 40, zinc', 0.42], ['KIP-1002', 'Flange nut M8', 0.18], ['KIP-2040', 'Bearing 6204-2RS', 6.75], ['KIP-3300', 'Hydraulic hose 1/2in x 2m', 38.5], ['KIP-4100', 'Safety gloves, cut level 5', 9.9], ...FASTENERS],
  brightline: [['BL-PAP-A4', 'Copy paper A4 80gsm (box of 5 reams)', 24.0], ['BL-TON-26X', 'Toner cartridge 26X', 129.0], ['BL-CHR-ERG', 'Ergonomic task chair', 289.0], ['BL-PEN-BLU', 'Gel pens blue (pack of 12)', 7.5]],
  harbor: [['HP-BOX-S', 'Corrugated box small 300x200x150', 0.85], ['HP-BOX-L', 'Corrugated box large 600x400x400', 2.1], ['HP-TAPE-48', 'Packing tape 48mm x 66m', 1.95], ['HP-WRAP-50', 'Stretch wrap 500mm x 300m', 18.4]],
  nimbus: [['NCS-VM-M', 'Managed VM medium (monthly)', 145.0], ['NCS-STO-1T', 'Object storage 1 TB (monthly)', 21.0], ['NCS-SUP-G', 'Gold support plan (monthly)', 250.0]],
  quill: [['QF-NB-A5', 'Notebook A5 dotted', 4.2], ['QF-ENV-C4', 'Envelopes C4 (box of 250)', 31.0]],
};
const GERMAN = { 'HP-BOX-S': 'Wellpappkarton klein 300x200x150', 'HP-BOX-L': 'Wellpappkarton gross 600x400x400', 'HP-TAPE-48': 'Packband 48mm x 66m', 'HP-WRAP-50': 'Stretchfolie 500mm x 300m' };
// The ISO 13616 documentation example IBAN — not a real account.
const EXAMPLE_IBAN = 'DE89 3704 0044 0532 0130 00';

const day = (offset) => new Date(BASE.getTime() + offset * 86400000).toISOString().slice(0, 10);
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DATE_STYLE = {
  iso: (d) => d,
  long: (d) => `${Number(d.slice(8))} ${MONTHS[Number(d.slice(5, 7)) - 1]} ${d.slice(0, 4)}`, // 14 Nov 2026
  us: (d) => `${MONTHS[Number(d.slice(5, 7)) - 1]} ${Number(d.slice(8))}, ${d.slice(0, 4)}`, // Nov 14, 2026
  de: (d) => `${d.slice(8)}.${d.slice(5, 7)}.${d.slice(0, 4)}`, // 14.11.2026
};
const money = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const euro = (n) => n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const round2 = (n) => Math.round(n * 100) / 100;
const totals = (lines, taxRate) => {
  const subtotal = round2(lines.reduce((s, l) => s + round2(l.qty * l.price), 0));
  const tax = round2(subtotal * taxRate);
  return { subtotal, tax, total: round2(subtotal + tax) };
};

const manifest = [];
for (const d of ['scenarios', 'bulk', 'scans']) fs.rmSync(path.join(here, 'pdf', d), { recursive: true, force: true });
const pending = []; // PDF write streams; scans are rasterised once they have flushed
const pad = (s, n) => String(s).padEnd(n).slice(0, n);
const lpad = (s, n) => String(s).padStart(n);

function open(file, doc) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const pdf = new PDFDocument({ size: 'A4', margin: 50, info: { Title: `${doc.title} ${doc.number}`, Author: doc.issuer.name, Producer: 'ExtractIQ sample generator', CreationDate: BASE } });
  const stream = fs.createWriteStream(file);
  pending.push(new Promise((resolve) => stream.on('finish', resolve)));
  pdf.pipe(stream);
  if (doc.skew) pdf.rotate(doc.skew, { origin: [pdf.page.width / 2, pdf.page.height / 2] });
  return pdf;
}

/** Classic: colour band, "Label: value" lines, monospace table. Paginates long tables and reprints the header. */
function renderClassic(file, doc) {
  const pdf = open(file, doc);
  const band = doc.band ?? '#4f46e5';
  const pageFooter = (n, of) => (pdf.page.margins.bottom = 0) || pdf.font('Helvetica').fontSize(8).fillColor('#9ca3af').text(of > 1 ? `${doc.note ?? ''}    Page ${n} of ${of}` : (doc.note ?? 'Thank you for your business.'), 50, 770, { width: 495, align: 'center', lineBreak: false });
  const perFirst = 30, perNext = 42;
  const pages = doc.rows.length <= perFirst ? 1 : 1 + Math.ceil((doc.rows.length - perFirst) / perNext);

  pdf.rect(0, 0, pdf.page.width, 8).fill(band);
  pdf.fillColor('#111827').font('Helvetica-Bold').fontSize(22).text(doc.title.toUpperCase(), 50, 40);
  pdf.font('Helvetica').fontSize(9).fillColor('#6b7280').text(doc.sampleLine ?? 'SAMPLE DOCUMENT - FICTIONAL DATA', 50, 68);
  pdf.fillColor('#111827').font('Helvetica-Bold').fontSize(11).text(doc.issuer.name, 330, 40, { width: 215, align: 'right' });
  pdf.font('Helvetica').fontSize(9).fillColor('#374151').text(doc.issuer.address.join('\n'), 330, 56, { width: 215, align: 'right' });

  let y = 110;
  pdf.font('Helvetica').fontSize(10).fillColor('#111827');
  for (const line of doc.meta) { pdf.text(line, 50, y); y += 15; }
  y += 15;
  const header = () => {
    pdf.rect(50, y - 4, 495, 18).fill('#f3f4f6');
    pdf.fillColor('#111827').font('Courier-Bold').fontSize(9).text(doc.header, 56, y);
    y += 22;
    pdf.font('Courier').fontSize(9).fillColor('#111827');
  };
  header();
  let page = 1, onPage = 0;
  for (const row of doc.rows) {
    if (onPage === (page === 1 ? perFirst : perNext)) {
      pdf.font('Helvetica').fontSize(9).fillColor('#6b7280').text('Continued on next page', 50, y + 6);
      pageFooter(page, pages);
      pdf.addPage({ margin: 50 });
      page++; onPage = 0; y = 60;
      pdf.font('Helvetica').fontSize(9).fillColor('#6b7280').text(`${doc.title} ${doc.number} (continued)`, 50, 40);
      header();
    }
    pdf.text(row, 56, y); y += 15; onPage++;
  }
  pdf.moveTo(50, y + 2).lineTo(545, y + 2).strokeColor('#e5e7eb').stroke();
  y += 14;
  for (const [i, line] of doc.footer.entries()) {
    const bold = i === doc.footer.length - 1 && doc.kind !== 'goods_receipt';
    pdf.fillColor('#111827').font(bold ? 'Helvetica-Bold' : 'Helvetica').fontSize(10).text(line, 300, y, { width: 245, align: 'right' });
    y += 16;
  }
  if (doc.bank) pdf.font('Helvetica').fontSize(9).fillColor('#374151').text(doc.bank, 50, y + 12);
  pageFooter(page, pages);
  if (doc.speckle) speckle(pdf, doc.speckle);
  pdf.end();
}

/** Photocopier speckle and toner streaks for the poor-quality scan (deterministic). */
function speckle(pdf, n) {
  for (let i = 0; i < n; i++) {
    const x = 40 + rand() * 515, y = 90 + rand() * 640;
    pdf.circle(x, y, 0.3 + rand() * 0.5).fill(rand() > 0.5 ? '#9ca3af' : '#6b7280');
  }
  for (let i = 0; i < 3; i++) {
    const x = 60 + rand() * 470;
    pdf.rect(x, 0, 0.6 + rand(), pdf.page.height).fillOpacity(0.18).fill('#4b5563').fillOpacity(1);
  }
}

/** Modern: no band, two-column meta (label and value side by side), proportional table columns. */
function renderModern(file, doc) {
  const pdf = open(file, doc);
  pdf.fillColor('#0f172a').font('Helvetica-Bold').fontSize(15).text(doc.issuer.name, 50, 48);
  pdf.font('Helvetica').fontSize(9).fillColor('#475569').text(doc.issuer.address.join(', '), 50, 68);
  pdf.font('Helvetica').fontSize(26).fillColor('#94a3b8').text(doc.title, 330, 42, { width: 215, align: 'right' });
  pdf.moveTo(50, 92).lineTo(545, 92).strokeColor('#cbd5e1').lineWidth(0.5).stroke();

  let y = 110;
  for (let i = 0; i < doc.meta.length; i += 2) {
    for (const [j, x] of [[0, 50], [1, 310]]) {
      const pair = doc.meta[i + j];
      if (!pair) continue;
      pdf.font('Helvetica').fontSize(9).fillColor('#64748b').text(pair[0], x, y);
      pdf.font('Helvetica-Bold').fontSize(10).fillColor('#0f172a').text(pair[1], x + 95, y - 0.5);
    }
    y += 18;
  }
  y += 16;
  const cols = [[50, 70, 'left'], [128, 230, 'left'], [360, 40, 'right'], [405, 65, 'right'], [475, 70, 'right']];
  const cells = (vals, font, size, color) => vals.forEach((v, i) => pdf.font(font).fontSize(size).fillColor(color).text(v, cols[i][0], y, { width: cols[i][1], align: cols[i][2], lineBreak: false }));
  cells(doc.headerCells, 'Helvetica-Bold', 8.5, '#475569');
  y += 14;
  pdf.moveTo(50, y).lineTo(545, y).strokeColor('#cbd5e1').stroke();
  y += 8;
  for (const r of doc.cells) { cells(r, 'Helvetica', 9.5, '#0f172a'); y += 18; }
  pdf.moveTo(50, y).lineTo(545, y).strokeColor('#cbd5e1').stroke();
  y += 12;
  for (const [i, [label, value]] of doc.footerPairs.entries()) {
    const last = i === doc.footerPairs.length - 1;
    pdf.font(last ? 'Helvetica-Bold' : 'Helvetica').fontSize(last ? 11 : 9.5).fillColor('#0f172a').text(label, 360, y).text(value, 445, y, { width: 100, align: 'right' });
    y += 17;
  }
  pdf.font('Helvetica').fontSize(8.5).fillColor('#64748b').text(doc.note ?? 'Thank you for your order.', 50, 760, { width: 495 });
  pdf.font('Helvetica').fontSize(7.5).fillColor('#94a3b8').text('Sample document - fictional data', 50, 775, { width: 495 });
  pdf.end();
}

/** Compact: small type, key/value box on the right, striped proportional table. */
function renderCompact(file, doc) {
  const pdf = open(file, doc);
  pdf.rect(50, 46, 4, 32).fill('#0e7490');
  pdf.fillColor('#111827').font('Helvetica-Bold').fontSize(12).text(doc.issuer.name, 62, 46);
  pdf.font('Helvetica').fontSize(8).fillColor('#4b5563').text(doc.issuer.address.join(' · '), 62, 63);
  pdf.font('Helvetica-Bold').fontSize(13).fillColor('#111827').text(doc.title, 62, 100);
  pdf.font('Helvetica').fontSize(8).fillColor('#4b5563').text(`Bill to: ${BUYER.name}`, 62, 118).text(BUYER.address.join(', '), 62, 129);

  let y = 46;
  for (const [label, value] of doc.meta) {
    pdf.font('Helvetica').fontSize(8).fillColor('#6b7280').text(label, 360, y);
    pdf.font('Helvetica').fontSize(8.5).fillColor('#111827').text(value, 440, y, { width: 105, align: 'right' });
    y += 13;
  }
  y = Math.max(y, 150) + 14;
  const cols = [[54, 70, 'left'], [128, 240, 'left'], [370, 40, 'right'], [412, 63, 'right'], [477, 64, 'right']];
  const cells = (vals, font, color) => vals.forEach((v, i) => pdf.font(font).fontSize(8.5).fillColor(color).text(v, cols[i][0], y, { width: cols[i][1], align: cols[i][2], lineBreak: false }));
  pdf.rect(50, y - 4, 495, 16).fill('#e5e7eb');
  cells(doc.headerCells, 'Helvetica-Bold', '#111827');
  y += 18;
  for (const [i, r] of doc.cells.entries()) {
    if (i % 2 === 1) pdf.rect(50, y - 4, 495, 16).fill('#f9fafb');
    cells(r, 'Helvetica', '#111827');
    y += 16;
  }
  y += 8;
  for (const [i, [label, value]] of doc.footerPairs.entries()) {
    const last = i === doc.footerPairs.length - 1;
    pdf.font(last ? 'Helvetica-Bold' : 'Helvetica').fontSize(9).fillColor('#111827').text(label, 370, y).text(value, 445, y, { width: 100, align: 'right' });
    y += 14;
  }
  pdf.font('Helvetica').fontSize(7.5).fillColor('#6b7280').text(`${doc.note ?? ''}  Sample document - fictional data.`, 50, 775, { width: 495 });
  pdf.end();
}

const rowsFor = (lines, withPrices, fmt = money) =>
  lines.map((l) => withPrices
    ? `${pad(l.sku, 12)} ${pad(l.desc, 38)} ${lpad(l.qty, 6)} ${lpad(fmt(l.price), 10)} ${lpad(fmt(round2(l.qty * l.price)), 11)}`
    : `${pad(l.sku, 12)} ${pad(l.desc, 52)} ${lpad(l.qty, 8)}`);
const HEADER_PRICED = `${pad('SKU', 12)} ${pad('Description', 38)} ${lpad('Qty', 6)} ${lpad('Unit Price', 10)} ${lpad('Amount', 11)}`;
const HEADER_DE = `${pad('Artikel', 12)} ${pad('Bezeichnung', 38)} ${lpad('Menge', 6)} ${lpad('Preis', 10)} ${lpad('Betrag', 11)}`;
const HEADER_GRN = `${pad('SKU', 12)} ${pad('Description', 52)} ${lpad('Received', 8)}`;

const seedPos = [];   // every purchase order the demo ERP knows, for samples/erp/seed.json
const seedGrns = [];
const vendorKeyOf = (vendor) => Object.keys(VENDORS).find((k) => VENDORS[k].name === vendor.name);
const filePath = (dir, file) => path.join(here, 'pdf', dir, file);

// Purchase orders and receipts are the buyer's own documents, always on the classic template.
function po({ dir, file, vendor, number, date, lines, taxRate = 0.08, expect }) {
  seedPos.push({ vendorKey: vendorKeyOf(vendor), number, date, lines });
  const t = totals(lines, taxRate);
  renderClassic(filePath(dir, file), {
    kind: 'purchase_order', title: 'Purchase Order', number, issuer: BUYER,
    meta: [`PO Number: ${number}`, `PO Date: ${date}`, `Supplier: ${vendor.name}`, `Ship To: ${BUYER.name}`, `Currency: ${vendor.currency}`, 'Payment Terms: Net 30'],
    header: HEADER_PRICED, rows: rowsFor(lines, true),
    footer: [`Subtotal: ${money(t.subtotal)}`, `Tax (${Math.round(taxRate * 100)}%): ${money(t.tax)}`, `Total: ${money(t.total)}`],
    note: 'Please quote the PO number on every invoice and delivery note.',
  });
  manifest.push({ file: `pdf/${dir}/${file}`, type: 'purchase_order', number, vendor: vendor.name, currency: vendor.currency, total: t.total, expect: expect ?? 'approved' });
  return t;
}

function grn({ dir, file, vendor, number, poNumber, date, lines, expect, inErp = true }) {
  if (inErp) seedGrns.push({ number, poNumber, date, lines });
  renderClassic(filePath(dir, file), {
    kind: 'goods_receipt', title: 'Goods Received Note', number, issuer: BUYER, band: '#334155',
    meta: [`GRN Number: ${number}`, `Receipt Date: ${date}`, `PO Number: ${poNumber}`, `Supplier: ${vendor.name}`, 'Received By: Warehouse A'],
    header: HEADER_GRN, rows: rowsFor(lines, false),
    footer: [`Total lines: ${lines.length}`],
    note: 'Goods inspected and received in good condition unless noted.',
  });
  manifest.push({ file: `pdf/${dir}/${file}`, type: 'goods_receipt', number, po_number: poNumber, vendor: vendor.name, expect: expect ?? 'approved' });
}

function invoice({ dir, file, vendor: realVendor, printedAs, number, poNumber, date, due, lines, taxRate = 0.08, expect, tamperTotal = 0, note, poLabel, layout, skew, speckle: dots, extra = {} }) {
  // printedAs: how the vendor names itself on this invoice (trading-name variants, no tax ID).
  const vendor = printedAs ? { ...realVendor, ...printedAs } : realVendor;
  const t = totals(lines, taxRate);
  const shownTotal = round2(t.total + tamperTotal);
  const style = layout ?? vendor.layout;
  const pct = `${Math.round(taxRate * 100)}%`;
  const out = filePath(dir, file);
  const base = { kind: 'invoice', number, issuer: vendor, skew, speckle: dots };

  if (style === 'german') {
    const d = DATE_STYLE.de;
    renderClassic(out, { ...base, title: 'Rechnung', band: '#1f2937', sampleLine: 'MUSTERDOKUMENT - FIKTIVE DATEN',
      meta: [`Rechnungsnummer: ${number}`, `Rechnungsdatum: ${d(date)}`, ...(due ? [`Zahlbar bis: ${d(due)}`] : []), ...(poNumber ? [`Bestellnummer: ${poNumber}`] : []),
        `Lieferant: ${vendor.name}`, `Rechnungsempfänger: ${BUYER.name}`, `Währung: ${vendor.currency}`, ...(vendor.tax ? [`USt-IdNr.: ${vendor.tax}`] : [])],
      header: HEADER_DE, rows: rowsFor(lines.map((l) => ({ ...l, desc: GERMAN[l.sku] ?? l.desc })), true, euro),
      footer: [`Zwischensumme: ${euro(t.subtotal)}`, `MwSt. (${pct}): ${euro(t.tax)}`, `Gesamtbetrag: ${euro(shownTotal)} EUR`],
      bank: `Bankverbindung: Musterbank, IBAN ${EXAMPLE_IBAN}, BIC COBADEFFXXX`,
      note: note ?? 'Bitte überweisen Sie den Betrag unter Angabe der Rechnungsnummer. Vielen Dank für Ihren Auftrag.' });
  } else if (style === 'modern') {
    const d = DATE_STYLE.long;
    renderModern(out, { ...base, title: 'Invoice',
      meta: [['Invoice No', number], ['Invoice Date', d(date)], ...(poNumber ? [[poLabel ?? 'PO Number', poNumber]] : []), ...(due ? [['Due Date', d(due)]] : []),
        ['Bill To', BUYER.name], ['Currency', vendor.currency], ...(vendor.tax ? [['Tax ID', vendor.tax]] : [])],
      headerCells: ['Item', 'Description', 'Qty', 'Unit price', 'Amount'],
      cells: lines.map((l) => [l.sku, l.desc, String(l.qty), money(l.price), money(round2(l.qty * l.price))]),
      footerPairs: [['Subtotal', money(t.subtotal)], [`Tax (${pct})`, money(t.tax)], ['Total', `${vendor.currency} ${money(shownTotal)}`]],
      note: note ?? `Payment due by ${d(due)}. Remit to account ending 4410.` });
  } else if (style === 'compact') {
    const d = DATE_STYLE.us;
    renderCompact(out, { ...base, title: 'Tax invoice',
      meta: [['Invoice #', number], ['Invoice date', d(date)], ...(due ? [['Payment due', d(due)]] : []), ...(poNumber ? [['PO #', poNumber]] : []), ['Currency', vendor.currency], ...(vendor.tax ? [['EIN', vendor.tax]] : [])],
      headerCells: ['Service', 'Description', 'Qty', 'Rate', 'Amount'],
      cells: lines.map((l) => [l.sku, l.desc, String(l.qty), money(l.price), money(round2(l.qty * l.price))]),
      footerPairs: [['Subtotal', money(t.subtotal)], [`Tax (${pct})`, money(t.tax)], ['Amount due', `${vendor.currency} ${money(shownTotal)}`]],
      note: note ?? 'Charges are billed monthly in advance.' });
  } else {
    renderClassic(out, { ...base, title: 'Invoice',
      meta: [`Invoice No: ${number}`, `Invoice Date: ${date}`, ...(due ? [`Due Date: ${due}`] : []), ...(poNumber ? [`${poLabel ?? 'PO Number'}: ${poNumber}`] : []), `Vendor: ${vendor.name}`, `Bill To: ${BUYER.name}`, `Currency: ${vendor.currency}`, ...(vendor.tax ? [`Tax ID: ${vendor.tax}`] : [])],
      header: HEADER_PRICED, rows: rowsFor(lines, true),
      footer: [`Subtotal: ${money(t.subtotal)}`, `Tax (${pct}): ${money(t.tax)}`, `Total: ${money(shownTotal)}`],
      note: note ?? `Please pay by ${due ?? 'the due date'}. Bank details on file.` });
  }
  manifest.push({ file: `pdf/${dir}/${file}`, type: 'invoice', number, po_number: poNumber ?? null, vendor: vendor.name, currency: vendor.currency, total: shownTotal, layout: style, ...extra, expect });
}

const L = (vendorKey, picks) => picks.map(([i, qty, priceOverride]) => {
  const [sku, desc, price] = CATALOG[vendorKey][i];
  return { sku, desc, qty, price: priceOverride ?? price };
});

// ---------------------------------------------------------------- scenarios
const S = 'scenarios';
const V = VENDORS;

// 1. Perfect 3-way match -> pushed
const l1 = L('kestrel', [[0, 500], [1, 500], [2, 40]]);
po({ dir: S, file: '01-po-1001-kestrel.pdf', vendor: V.kestrel, number: 'PO-1001', date: day(-20), lines: l1 });
grn({ dir: S, file: '02-grn-5001-kestrel.pdf', vendor: V.kestrel, number: 'GRN-5001', poNumber: 'PO-1001', date: day(-12), lines: l1 });
invoice({ dir: S, file: '03-inv-k-2041-kestrel-3way-match.pdf', vendor: V.kestrel, number: 'INV-K-2041', poNumber: 'PO-1001', date: day(-11), due: day(19), lines: l1, expect: 'pushed' });

// 2. Clean 2-way match (no GRN, services/consumables) -> pushed
const l2 = L('brightline', [[0, 20], [1, 4], [3, 10]]);
po({ dir: S, file: '04-po-1002-brightline.pdf', vendor: V.brightline, number: 'PO-1002', date: day(-15), lines: l2 });
invoice({ dir: S, file: '05-inv-bl-7781-brightline-2way-match.pdf', vendor: V.brightline, number: 'INV-BL-7781', poNumber: 'PO-1002', date: day(-9), due: day(21), lines: l2, expect: 'pushed' });

// 3. 3-way exception: invoiced more than received (EUR)
const l3 = L('harbor', [[0, 1000], [2, 120]]);
po({ dir: S, file: '06-po-1003-harbor-eur.pdf', vendor: V.harbor, number: 'PO-1003', date: day(-18), lines: l3, taxRate: 0.19 });
grn({ dir: S, file: '07-grn-5003-harbor-partial.pdf', vendor: V.harbor, number: 'GRN-5003', poNumber: 'PO-1003', date: day(-8), lines: L('harbor', [[0, 800], [2, 120]]) });
invoice({ dir: S, file: '08-inv-hp-3310-harbor-qty-exception.pdf', vendor: V.harbor, number: 'HP-3310', poNumber: 'PO-1003', date: day(-7), due: day(23), lines: l3, taxRate: 0.19, expect: 'match_exception' });

// 4. 2-way exception: unit price 6% above PO
const l4 = L('kestrel', [[3, 12], [4, 50]]);
po({ dir: S, file: '09-po-1004-kestrel.pdf', vendor: V.kestrel, number: 'PO-1004', date: day(-10), lines: l4 });
invoice({ dir: S, file: '10-inv-k-2055-kestrel-price-variance.pdf', vendor: V.kestrel, number: 'INV-K-2055', poNumber: 'PO-1004', date: day(-4), due: day(26), lines: L('kestrel', [[3, 12, 40.81], [4, 50]]), expect: 'match_exception' });

// 5. No PO on invoice -> validated (warning) -> the open PO-1007 (from the ERP export) is found by vendor + amount -> pushed
invoice({ dir: S, file: '11-ncs-9001-nimbus-no-po.pdf', vendor: V.nimbus, number: 'NCS-9001', date: day(-3), due: day(27), lines: L('nimbus', [[0, 3], [1, 2], [2, 1]]), taxRate: 0, expect: 'pushed (PO-1007 found by vendor + amount)' });

// 6. Arithmetic error: printed total doesn't equal subtotal + tax -> needs_review
invoice({ dir: S, file: '12-inv-bl-7790-brightline-total-mismatch.pdf', vendor: V.brightline, number: 'INV-BL-7790', poNumber: 'PO-1002', date: day(-2), due: day(28), lines: L('brightline', [[2, 2]]), tamperTotal: 50, expect: 'needs_review' });

// 7. Unknown vendor -> needs_review
invoice({ dir: S, file: '13-qf-118-unknown-vendor.pdf', vendor: V.quill, number: 'QF-118', date: day(-1), due: day(29), lines: L('quill', [[0, 40], [1, 2]]), expect: 'needs_review' });

// 9–10. Vendor memory: Brightline prints the PO as "Order Ref". The first one needs a reviewer to fill in the PO
// (that correction teaches ExtractIQ the label); the second is then extracted correctly on its own.
const l15 = L('brightline', [[0, 5], [3, 4]]);
po({ dir: S, file: '15-po-1005-brightline.pdf', vendor: V.brightline, number: 'PO-1005', date: day(-6), lines: l15 });
invoice({ dir: S, file: '16-inv-bl-7801-order-ref.pdf', vendor: V.brightline, number: 'INV-BL-7801', poNumber: 'PO-1005', poLabel: 'Order Ref', date: day(-2), due: day(28), lines: l15, expect: 'validated with a missing-PO warning → reviewer fills PO → learns "Order Ref"' });
const l17 = L('brightline', [[1, 1]]);
po({ dir: S, file: '17-po-1006-brightline.pdf', vendor: V.brightline, number: 'PO-1006', date: day(-5), lines: l17 });
invoice({ dir: S, file: '18-inv-bl-7802-order-ref.pdf', vendor: V.brightline, number: 'INV-BL-7802', poNumber: 'PO-1006', poLabel: 'Order Ref', date: day(-1), due: day(29), lines: l17, expect: 'pushed (PO found via the learned hint)' });

// ---------------------------------------------------------------- purchase orders 7–10 and their receipts
// Also in csv/ — the same records as a spreadsheet export, for trying Settings → Master data → Import.
const ERP_REF = { kestrel: 'V-100', brightline: 'V-200', harbor: 'V-300', nimbus: 'V-400' };
const ALIASES = { kestrel: ['Kestrel Parts', 'KIP'], brightline: ['Brightline Office'], harbor: ['Harbor and Pine', 'Harbour & Pine'], nimbus: ['Nimbus Cloud'] };
const csvPos = [];
const csvGrns = [];
const po2 = (o) => { csvPos.push({ vendorKey: vendorKeyOf(o.vendor), number: o.number, date: o.date, lines: o.lines }); return po(o); };
const grn2 = (o) => { csvGrns.push({ number: o.number, poNumber: o.poNumber, date: o.date, lines: o.lines }); return grn(o); };

po2({ dir: S, file: '23-po-1007-nimbus.pdf', vendor: V.nimbus, number: 'PO-1007', date: day(-25), lines: L('nimbus', [[0, 3], [1, 2], [2, 1]]), taxRate: 0 });
po2({ dir: S, file: '24-po-1008-kestrel.pdf', vendor: V.kestrel, number: 'PO-1008', date: day(-14), lines: L('kestrel', [[2, 40], [3, 10], [4, 100]]) });
grn2({ dir: S, file: '25-grn-5008-kestrel-partial.pdf', vendor: V.kestrel, number: 'GRN-5008', poNumber: 'PO-1008', date: day(-6), lines: L('kestrel', [[2, 40], [3, 6]]) }); // hose: 6 of 10; gloves not delivered yet
po2({ dir: S, file: '26-po-1009-brightline.pdf', vendor: V.brightline, number: 'PO-1009', date: day(-9), lines: L('brightline', [[0, 30], [1, 6]]) });
po2({ dir: S, file: '27-po-1010-harbor.pdf', vendor: V.harbor, number: 'PO-1010', date: day(-12), lines: L('harbor', [[3, 10]]), taxRate: 0.19 });
grn2({ dir: S, file: '28-grn-5010-harbor.pdf', vendor: V.harbor, number: 'GRN-5010', poNumber: 'PO-1010', date: day(-5), lines: L('harbor', [[3, 10]]) });

// 11. Partial delivery, billed exactly as delivered -> 3-way matched -> pushed
invoice({ dir: S, file: '19-inv-k-2070-kestrel-partial-delivery.pdf', vendor: V.kestrel, number: 'INV-K-2070', poNumber: 'PO-1008', date: day(-5), due: day(25), lines: L('kestrel', [[2, 40], [3, 6]]), expect: 'pushed (3-way, partial delivery)' });
// 12. Over-billing + not received: 4 more hoses (only 6 received, 6 already billed) and gloves that never arrived
invoice({ dir: S, file: '20-inv-k-2071-kestrel-overbilled.pdf', vendor: V.kestrel, number: 'INV-K-2071', poNumber: 'PO-1008', date: day(-2), due: day(28), lines: L('kestrel', [[3, 4], [4, 100]]), expect: 'match_exception (qty_variance: over-billed; not_received)' });
// 13. Vendor's own SKUs (paired by description) and a courier fee that isn't on the PO
invoice({ dir: S, file: '21-inv-bl-7820-brightline-own-skus.pdf', vendor: V.brightline, number: 'INV-BL-7820', poNumber: 'PO-1009', date: day(-1), due: day(29),
  lines: [{ sku: 'PAPA4-5', desc: 'A4 copy paper, 80gsm, 5-ream box', qty: 30, price: 24.0 }, { sku: 'TN-26X', desc: 'Toner cartridge 26X, black', qty: 6, price: 129.0 }, { sku: 'DLV-01', desc: 'Next-day courier', qty: 1, price: 35.0 }],
  expect: 'match_exception (lines paired by description; not_on_po: courier fee)' });
// 14. Vendor printed under a trading-name variant without a tax ID -> resolved by fuzzy name -> 3-way matched
invoice({ dir: S, file: '22-inv-hp-3320-harbour-trading-name.pdf', vendor: V.harbor, printedAs: { name: 'Harbour & Pine Packaging GmbH', tax: null }, number: 'HP-3320', poNumber: 'PO-1010', date: day(-1), due: day(29), lines: L('harbor', [[3, 10]]), taxRate: 0.19, expect: 'pushed (vendor matched by fuzzy name)' });

// ---------------------------------------------------------------- history: 16 invoices against ERP-only orders
// PO-2001…PO-2016 exist only in the demo ERP (erp/seed.json, "bulk": true), the way most orders do:
// nobody scans their own POs. Six of them have a goods received note on paper.
const B = 'bulk';
const HISTORY = [
  { key: 'kestrel', grn: true }, { key: 'nimbus' }, { key: 'harbor', grn: true }, { key: 'brightline' },
  { key: 'kestrel', multiPage: true }, { key: 'harbor', grn: true, layout: 'german' }, { key: 'nimbus' }, { key: 'brightline', skewedScan: true },
  { key: 'kestrel', grn: true }, { key: 'nimbus' }, { key: 'harbor' }, { key: 'brightline', grn: true },
  { key: 'kestrel' }, { key: 'nimbus' }, { key: 'harbor', grn: true }, { key: 'brightline' },
];
const scans = [];
let grnNo = 6001;
HISTORY.forEach((h, i) => {
  const vendor = V[h.key];
  const catalog = h.key === 'kestrel' ? CATALOG.kestrel.slice(0, 5) : CATALOG[h.key];
  let lines;
  if (h.multiPage) {
    lines = L('kestrel', FASTENERS.map((_, j) => [5 + j, int(2, 20) * 25]).slice(0, 35).concat([[0, 400], [1, 400], [2, 24], [3, 4], [4, 60]]));
  } else {
    const n = int(1, Math.min(4, catalog.length));
    const idx = [...catalog.keys()].sort(() => rand() - 0.5).slice(0, n);
    lines = L(h.key, idx.map((j) => [j, catalog[j][2] < 5 ? int(50, 800) : int(1, 30)]));
  }
  const taxRate = h.key === 'harbor' ? 0.19 : h.key === 'nimbus' ? 0 : 0.08;
  const poNo = `PO-${2001 + i}`;
  const start = -int(5, 40);
  seedPos.push({ vendorKey: h.key, number: poNo, date: day(start), lines, bulk: true });
  if (h.grn) {
    const n = grnNo++;
    grn({ dir: B, file: `grn-${n}.pdf`, vendor, number: `GRN-${n}`, poNumber: poNo, date: day(start + 4), lines, inErp: false });
  }
  const number = `${vendor.prefix}${3001 + i}`;
  const inv = { vendor, number, poNumber: poNo, date: day(start + 5), due: day(start + 35), lines, taxRate };
  if (h.skewedScan) {
    scans.push({ ...inv, name: `29-scan-${number.toLowerCase()}-skewed`, skew: 1.4, layout: 'classic', dpi: 150, expect: 'needs_review (skewed scan: a couple of fields under 90% confidence)' });
    return;
  }
  invoice({ dir: B, file: `invoice-${number.toLowerCase()}${h.layout === 'german' ? '-de' : h.multiPage ? '-multipage' : ''}.pdf`, ...inv, layout: h.layout,
    extra: { ...(h.multiPage ? { pages: 2 } : {}), ...(h.layout === 'german' ? { language: 'de' } : {}) }, expect: 'pushed' });
});

// 8. A scanned invoice (no text layer, no PO) — Tesseract with mixed confidence, then PO-not-found.
scans.unshift({ vendor: V.nimbus, number: 'NCS-9050', lines: L('nimbus', [[0, 2], [2, 1]]), date: day(-1), due: day(29), taxRate: 0, name: '14-scan-ncs-9050', layout: 'classic', dpi: 85, speckle: 450, expect: 'needs_review (scan: low-confidence lines, no PO)' });

/**
 * The demo ERP: vendors, products, purchase orders PO-1001…1010 (the scenarios) and PO-2001…2016
 * (history, "bulk": true) with their receipts. Loaded into the Mock ERP by the demo seed, and into a
 * real Odoo by `php artisan extractiq:odoo-seed` (bulk orders only with --all).
 */
function writeErpSeed() {
  const dir = path.join(here, 'erp');
  fs.mkdirSync(dir, { recursive: true });
  const lineOut = (l, i) => ({ line_no: i + 1, sku: l.sku, description: l.desc, quantity: l.qty, uom: 'ea', unit_price: l.price });
  const pos = [...seedPos].sort((a, b) => a.number.localeCompare(b.number));
  const grns = [...seedGrns].sort((a, b) => a.number.localeCompare(b.number));
  const seed = {
    generated_for: BASE.toISOString().slice(0, 10),
    vendors: Object.entries(ERP_REF).map(([k, code]) => ({ code, name: V[k].name, tax_id: V[k].tax, currency: V[k].currency, aliases: ALIASES[k] })),
    products: Object.entries(CATALOG).filter(([k]) => ERP_REF[k]).flatMap(([k, items]) => items.map(([sku, name, price]) => ({ sku, name, cost: price, vendor_code: ERP_REF[k] }))),
    purchase_orders: pos.map((p) => ({ number: p.number, vendor_code: ERP_REF[p.vendorKey], order_date: p.date, currency: V[p.vendorKey].currency, ...(p.bulk ? { bulk: true } : {}), lines: p.lines.map(lineOut) })),
    receipts: grns.map((g) => {
      const po = pos.find((p) => p.number === g.poNumber);
      return { number: g.number, po_number: g.poNumber, received_date: g.date, lines: g.lines.map((l, i) => ({ line_no: i + 1, po_line_no: po.lines.findIndex((pl) => pl.sku === l.sku) + 1, sku: l.sku, description: l.desc, quantity: l.qty })) };
    }),
  };
  fs.writeFileSync(path.join(dir, 'seed.json'), JSON.stringify(seed, null, 2) + '\n');
}

function writeCsv() {
  const dir = path.join(here, 'csv');
  fs.mkdirSync(dir, { recursive: true });
  const q = (v) => (v === null || v === undefined ? '' : /[",\n]/.test(String(v)) ? `"${String(v).replace(/"/g, '""')}"` : String(v));
  const out = (file, header, rows) => fs.writeFileSync(path.join(dir, file), [header, ...rows].map((r) => r.map(q).join(',')).join('\n') + '\n');
  out('vendors.csv', ['name', 'tax_id', 'aliases', 'email', 'default_currency', 'erp_ref'],
    Object.entries(ERP_REF).map(([k, ref]) => [V[k].name, V[k].tax, ALIASES[k].join(';'), null, V[k].currency, ref]));
  out('purchase_orders.csv', ['po_number', 'vendor', 'order_date', 'currency', 'line_no', 'sku', 'description', 'quantity', 'uom', 'unit_price'],
    csvPos.flatMap((p) => p.lines.map((l, i) => [p.number, ERP_REF[p.vendorKey], p.date, V[p.vendorKey].currency, i + 1, l.sku, l.desc, l.qty, 'ea', l.price.toFixed(2)])));
  out('goods_receipts.csv', ['grn_number', 'po_number', 'received_date', 'line_no', 'sku', 'description', 'quantity'],
    csvGrns.flatMap((g) => g.lines.map((l, i) => [g.number, g.poNumber, g.date, i + 1, l.sku, l.desc, l.qty])));
}

// Scans: render the invoice to a temporary PDF (rotated for the skewed one), rasterise it to a greyscale
// PNG with poppler, and wrap the image in an image-only PDF — what a scanner or phone app sends.
function renderScans() {
  try { execFileSync('pdftoppm', ['-v'], { stdio: 'ignore' }); } catch { console.warn('pdftoppm not found — skipping scanned samples'); return []; }
  const dir = path.join(here, 'pdf', 'scans');
  fs.mkdirSync(dir, { recursive: true });
  return scans.map((sc) => {
    const tmp = path.join(dir, `_${sc.name}.pdf`);
    const before = manifest.length;
    const flushed = pending.length;
    invoice({ dir: 'scans', file: `_${sc.name}.pdf`, ...sc, extra: { scanned: true, ...(sc.skew ? { skew_degrees: sc.skew } : {}) } });
    const entry = manifest.splice(before, 1)[0];
    const finalEntry = { ...entry, file: `pdf/scans/${sc.name}.pdf` };
    delete finalEntry.layout;
    return pending[flushed].then(() => new Promise((resolve) => {
      execFileSync('pdftoppm', ['-gray', '-r', String(sc.dpi), '-png', '-singlefile', tmp, path.join(dir, `_${sc.name}`)]);
      fs.unlinkSync(tmp);
      const png = path.join(dir, `_${sc.name}.png`);
      const pdf = new PDFDocument({ size: 'A4', margin: 0, info: { Title: `Scan ${sc.number}`, Producer: 'ExtractIQ sample generator (scan)', CreationDate: BASE } });
      const stream = fs.createWriteStream(path.join(dir, `${sc.name}.pdf`));
      stream.on('finish', () => { fs.unlinkSync(png); resolve(finalEntry); });
      pdf.pipe(stream);
      pdf.image(png, 0, 0, { width: pdf.page.width, height: pdf.page.height });
      pdf.end();
    }));
  });
}

function writeManifest() {
  const count = (t) => manifest.filter((d) => d.type === t).length;
  fs.writeFileSync(path.join(here, 'manifest.json'), JSON.stringify({ generated_for: BASE.toISOString().slice(0, 10), buyer: BUYER.name, vendors: Object.values(V).map(({ name, tax, currency }) => ({ name, tax_id: tax, currency })), counts: { invoice: count('invoice'), purchase_order: count('purchase_order'), goods_receipt: count('goods_receipt') }, documents: manifest, master_data: ['erp/seed.json', 'csv/vendors.csv', 'csv/purchase_orders.csv', 'csv/goods_receipts.csv'] }, null, 2) + '\n');
  console.log(`Generated ${count('invoice')} invoices, ${count('purchase_order')} purchase orders, ${count('goods_receipt')} goods received notes -> ${path.join(here, 'pdf')}`);
}


await Promise.all(pending);
manifest.push(...(await Promise.all(renderScans())));
writeCsv();
writeErpSeed();
writeManifest();
