const pptxgen = require("pptxgenjs");
const p = new pptxgen();
p.defineLayout({ name: "W", width: 13.333, height: 7.5 });
p.layout = "W";

// ---- Palette: Industrial Steel ----
const NAVY = "16314A";
const STEEL = "274C68";
const STEEL2 = "1F3F5C";
const ICE = "EAF1F6";
const ORANGE = "F2933D";
const TEAL = "2BA199";
const SKY = "56CCF2";
const GOLD = "F2C94C";
const INK = "1B2733";
const MUTE = "6B7C8C";
const WHITE = "FFFFFF";
const LIGHTBLUE = "CADCFC";

const HF = "Trebuchet MS";
const BF = "Calibri";
const W = 13.333, H = 7.5;
const SHOTS = "C:/xampp/htdocs/menam-workflow/docs/die-tracking/shots/";

function card(s, x, y, w, h, fill, line) {
  s.addShape(p.ShapeType.roundRect, {
    x, y, w, h, rectRadius: 0.1,
    fill: { color: fill }, line: line ? { color: line, width: 1 } : { type: "none" },
    shadow: { type: "outer", color: "9AA9B5", blur: 6, offset: 2, angle: 90, opacity: 0.22 },
  });
}
function iconCircle(s, x, y, d, fill) {
  s.addShape(p.ShapeType.ellipse, { x, y, w: d, h: d, fill: { color: fill }, line: { type: "none" } });
}
function kicker(s, txt, color) {
  s.addText(txt, { x: 0.72, y: 0.42, w: 11.5, h: 0.4, fontFace: HF, fontSize: 14, bold: true, color: color || ORANGE });
}
function heading(s, txt, color) {
  s.addText(txt, { x: 0.7, y: 0.78, w: 12, h: 0.7, fontFace: HF, fontSize: 30, bold: true, color: color || NAVY });
}
function newTag(s, x, y, color) {
  s.addShape(p.ShapeType.roundRect, { x, y, w: 0.9, h: 0.42, rectRadius: 0.21, fill: { color }, line: { type: "none" } });
  s.addText("ใหม่", { x, y, w: 0.9, h: 0.42, align: "center", valign: "middle", fontFace: HF, fontSize: 13, bold: true, color: NAVY });
}
// framed real screenshot
function shot(s, file, x, y, w, h) {
  const pad = 0.09;
  s.addShape(p.ShapeType.roundRect, {
    x: x - pad, y: y - pad, w: w + 2 * pad, h: h + 2 * pad, rectRadius: 0.05,
    fill: { color: WHITE }, line: { color: "C9D5DF", width: 1 },
    shadow: { type: "outer", color: "7E8C99", blur: 9, offset: 3, angle: 90, opacity: 0.35 },
  });
  s.addImage({ path: SHOTS + file, x, y, w, h });
}
function bullet(s, x, y, color, txt, tw) {
  iconCircle(s, x, y + 0.06, 0.28, color);
  s.addText("✓", { x, y: y + 0.04, w: 0.28, h: 0.28, align: "center", valign: "middle", fontFace: BF, fontSize: 13, bold: true, color: WHITE });
  s.addText(txt, { x: x + 0.45, y: y - 0.07, w: tw, h: 0.6, fontFace: BF, fontSize: 15.5, color: INK });
}

// =================================================================
// SLIDE 1 — COVER
// =================================================================
let s = p.addSlide();
s.background = { color: NAVY };
s.addShape(p.ShapeType.ellipse, { x: 11.3, y: -1.5, w: 4.2, h: 4.2, fill: { color: STEEL }, line: { type: "none" } });
s.addShape(p.ShapeType.ellipse, { x: 12.8, y: 4.5, w: 3.4, h: 3.4, fill: { color: STEEL }, line: { type: "none" } });
s.addShape(p.ShapeType.rect, { x: 0, y: 6.95, w: W, h: 0.12, fill: { color: ORANGE }, line: { type: "none" } });
s.addText("ระบบติดตามการใช้งานไดร์  ·  Die Tracking", { x: 0.9, y: 1.75, w: 10.5, h: 0.5, fontFace: HF, fontSize: 16, color: ORANGE, bold: true });
s.addText("รู้ทุกความเคลื่อนไหวของไดร์\nตั้งแต่หน้างานรายวัน ถึงการย้อนรอยปัญหา", { x: 0.9, y: 2.35, w: 11.3, h: 1.8, fontFace: HF, fontSize: 36, color: WHITE, bold: true, lineSpacingMultiple: 1.08 });
s.addText("ความสามารถใหม่ทั้งหมด ที่เพิ่งเปิดใช้งานเป็นครั้งแรก — รวมข้อมูลการใช้ไดร์จากโรงงาน W และ P ไว้ในที่เดียว",
  { x: 0.92, y: 4.5, w: 11, h: 0.6, fontFace: BF, fontSize: 17, color: LIGHTBLUE });
const pills = ["เบิกไดร์รายวัน", "ตรงตามใบสั่งผลิต", "ย้อนรอยจากใบเคลม", "ดูไดร์รายตัว"];
let bx = 0.92;
pills.forEach(b => {
  const bw = 0.5 + b.length * 0.17;
  s.addShape(p.ShapeType.roundRect, { x: bx, y: 5.5, w: bw, h: 0.55, rectRadius: 0.27, fill: { color: STEEL }, line: { color: ORANGE, width: 1 } });
  s.addText(b, { x: bx, y: 5.5, w: bw, h: 0.55, align: "center", fontFace: BF, fontSize: 13, bold: true, color: WHITE });
  bx += bw + 0.22;
});

// =================================================================
// SLIDE 2 — OVERVIEW
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
kicker(s, "ของใหม่ทั้งหมด — เมื่อก่อนยังทำไม่ได้");
heading(s, "ระบบใหม่นี้ทำอะไรให้เราได้บ้าง");
const cores = [
  ["เห็นการเบิกไดร์รายวัน", "รู้ว่าแต่ละวันมีการเบิกไดร์ไปลงที่บล็อกไหนบ้าง", ORANGE],
  ["ตรงตามใบสั่งผลิตไหม", "เทียบให้ว่าแต่ละงานเบิกไดร์ครบและตรงตามแผนผลิตหรือเปล่า", TEAL],
  ["ย้อนรอยจากใบเคลม (CN)", "ดึงใบเคลมของลูกค้ามาดู แล้วตามกลับไปที่งานผลิตได้", SKY],
  ["ดูไดร์เป็นรายตัว", "ไดร์ตัวนี้เคยผลิตงานอะไร น้ำหนักรวมเท่าไร เบิกไปที่ไหนบ้าง", GOLD],
];
let ox = 0.7, oy = 2.0, ow = 5.85, oh = 2.1, og = 0.3;
cores.forEach((c, i) => {
  const col = i % 2, row = Math.floor(i / 2);
  const x = ox + col * (ow + og), y = oy + row * (oh + og);
  card(s, x, y, ow, oh, ICE);
  iconCircle(s, x + 0.32, y + 0.34, 0.7, c[2]);
  s.addText(String(i + 1), { x: x + 0.32, y: y + 0.34, w: 0.7, h: 0.7, align: "center", valign: "middle", fontFace: HF, fontSize: 26, bold: true, color: WHITE });
  s.addText(c[0], { x: x + 1.25, y: y + 0.36, w: ow - 1.5, h: 0.65, fontFace: HF, fontSize: 20, bold: true, color: NAVY });
  s.addText(c[1], { x: x + 1.25, y: y + 1.08, w: ow - 1.5, h: 0.85, fontFace: BF, fontSize: 14.5, color: INK });
});

// =================================================================
// SLIDE 3 — CAP1: daily, by block (real screenshot)
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
kicker(s, "ความสามารถที่ 1");
heading(s, "เห็นการเบิกไดร์ทุกวัน ว่าไปลงบล็อกไหน");
s.addText("เลือกวันหรือช่วงเวลา ระบบจะสรุปให้ว่ามีการเบิกไดร์ไปลงบล็อกใดบ้าง ของงานไหน เครื่องไหน",
  { x: 0.72, y: 1.62, w: 5.1, h: 0.9, fontFace: BF, fontSize: 15.5, color: INK, lineSpacingMultiple: 1.12 });
bullet(s, 0.85, 3.05, ORANGE, "ดูรายวัน หรือเลือกช่วงเวลาเองได้", 4.6);
bullet(s, 0.85, 3.78, ORANGE, "รู้ว่าไดร์ลงบล็อกไหน งานใด", 4.6);
bullet(s, 0.85, 4.51, ORANGE, "เห็นเครื่องและแผนกที่ใช้", 4.6);
bullet(s, 0.85, 5.24, ORANGE, "เลือกทีละโรงงาน หรือรวม W + P", 4.6);
// portrait-ish table on right (ratio 1.52)
shot(s, "cap-daily-blocks.png", 6.05, 2.0, 6.55, 4.31);

// =================================================================
// SLIDE 4 — CAP2: matches the production order (wide screenshot)
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
kicker(s, "ความสามารถที่ 2");
heading(s, "เช็คว่าแต่ละงานเบิกไดร์ตรงตามแผนผลิตไหม");
s.addText("เปิดดูทีละใบสั่งผลิต ระบบเทียบให้ว่าแต่ละบล็อกใช้ไดร์ตรงตามขนาดในแผนผลิตหรือไม่ ครบทุกบล็อกหรือยัง พร้อมโยงเลขไดร์ (CN) รายบล็อก",
  { x: 0.72, y: 1.6, w: 11.9, h: 0.7, fontFace: BF, fontSize: 15.5, color: INK });
// very wide image (ratio 5.032) — full width
shot(s, "cap-wo-plan.png", 0.72, 2.55, 11.9, 2.365);
const chips4 = [["ครบทุกบล็อกหรือยัง", TEAL], ["เทียบขนาดตามแผนผลิต", ORANGE], ["โยงเลขไดร์ (CN) รายบล็อก", SKY]];
let c4x = 0.72, c4w = 3.85, c4g = 0.18;
chips4.forEach((c, i) => {
  const x = c4x + i * (c4w + c4g);
  card(s, x, 5.35, c4w, 1.0, ICE);
  s.addShape(p.ShapeType.rect, { x, y: 5.35, w: 0.12, h: 1.0, fill: { color: c[1] }, line: { type: "none" } });
  s.addText(c[0], { x: x + 0.35, y: 5.35, w: c4w - 0.55, h: 1.0, valign: "middle", fontFace: HF, fontSize: 16, bold: true, color: NAVY });
});

// =================================================================
// SLIDE 5 — CAP3: CN / claims (banner + detail screenshots)
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
kicker(s, "ความสามารถที่ 3");
heading(s, "ดึงใบเคลม (CN) มาดู แล้วย้อนรอยกลับไปที่การผลิต");
s.addText("ระบบสรุปจำนวนใบเคลมในรอบที่ผ่านมา พร้อมโยงกลับไปยังใบสั่งผลิต — ตามดูได้ว่ากระบวนการผลิตและไดร์ที่ใช้ควรปรับตรงไหน",
  { x: 0.72, y: 1.58, w: 11.9, h: 0.6, fontFace: BF, fontSize: 15.5, color: INK });
// banner (ratio 11.815)
shot(s, "cap-claim-banner.png", 1.17, 2.35, 11.0, 0.931);
// detail (ratio 6.905)
shot(s, "cap-claim-detail.png", 0.72, 3.65, 11.9, 1.724);
s.addText("ตัวอย่าง: ใบเคลม EC โยงถึง Sales Order และใบสั่งผลิต พร้อมรายละเอียด/เหตุผลการเคลม เพื่อย้อนรอยปรับปรุงคุณภาพ",
  { x: 0.72, y: 5.65, w: 11.9, h: 0.6, fontFace: BF, fontSize: 14, italic: true, color: MUTE });

// =================================================================
// SLIDE 6 — CAP4: per-die (kg focus, lifecycle screenshot)
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
kicker(s, "ความสามารถที่ 4");
heading(s, "ดูไดร์ทีละตัว ว่าทำงานอะไรมาบ้าง");
s.addText("ไดร์แต่ละตัวเคยผลิตงานอะไรมาบ้าง สร้างน้ำหนักงานรวมไปเท่าไร และเคลื่อนไหวอย่างไร",
  { x: 0.72, y: 1.58, w: 11.9, h: 0.5, fontFace: BF, fontSize: 15.5, color: INK });
// left stat chips (kg focused — NO meter)
const st = [
  ["น้ำหนักงานสะสม", "รวมงานสำเร็จที่ไดร์ช่วยผลิต (กก.)", TEAL],
  ["เคยผลิตกี่งาน", "จำนวนใบสั่งผลิต (WO) ที่เคยใช้", ORANGE],
  ["เบิกไปกี่แผนก", "และตำแหน่งล่าสุดของไดร์ตอนนี้", GOLD],
];
let sy = 2.35;
st.forEach(c => {
  card(s, 0.72, sy, 3.55, 1.2, ICE);
  s.addShape(p.ShapeType.rect, { x: 0.72, y: sy, w: 0.12, h: 1.2, fill: { color: c[2] }, line: { type: "none" } });
  s.addText(c[0], { x: 1.0, y: sy + 0.16, w: 3.1, h: 0.45, fontFace: HF, fontSize: 16.5, bold: true, color: NAVY });
  s.addText(c[1], { x: 1.0, y: sy + 0.62, w: 3.15, h: 0.5, fontFace: BF, fontSize: 12.5, color: INK });
  sy += 1.36;
});
// right lifecycle screenshot (ratio 1.904)
shot(s, "cap-lifecycle.png", 4.65, 2.3, 7.95, 4.176);

// =================================================================
// SLIDE 7 — supporting tools + current machines screenshot
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
kicker(s, "และยังมีอีก");
heading(s, "เครื่องมือเสริมที่ช่วยให้เห็นภาพครบ");
// current machines screenshot (ratio 3.214)
shot(s, "cap-current-machines.png", 0.72, 2.2, 7.7, 2.396);
newTag(s, 8.85, 2.2, TEAL);
s.addText("ไดร์บนเครื่องรีด ณ ปัจจุบัน", { x: 8.85, y: 2.75, w: 3.8, h: 0.7, fontFace: HF, fontSize: 19, bold: true, color: NAVY });
s.addText("เห็นว่าเครื่องรีดแต่ละตัวกำลังใช้ไดร์ตัวไหนอยู่ จัดกลุ่มตามเครื่องจักร เห็นภาระงานหน้างานทันที",
  { x: 8.85, y: 3.5, w: 3.85, h: 1.1, fontFace: BF, fontSize: 14, color: INK, lineSpacingMultiple: 1.12 });
// bottom row — other supporting tools
const extra = [
  ["ไดร์อยู่ที่ไหน", "ตำแหน่งล่าสุดทุกตัว", ORANGE],
  ["ไดร์ที่ถูกทิ้งไว้", "ไม่ถูกใช้นาน", SKY],
  ["ผลิตงานมากสุด", "จัดอันดับตามน้ำหนัก", GOLD],
  ["เทียบช่วงเวลา", "ยอดนี้กับช่วงก่อน", TEAL],
  ["ส่งออก Excel", "ดึงข้อมูลไปใช้ต่อ", ORANGE],
];
let exx = 0.72, exw = 2.26, exg = 0.15, exy = 5.15;
extra.forEach((c, i) => {
  const x = exx + i * (exw + exg);
  card(s, x, exy, exw, 1.45, ICE);
  s.addShape(p.ShapeType.rect, { x, y: exy, w: exw, h: 0.12, fill: { color: c[2] }, line: { type: "none" } });
  s.addText(c[0], { x: x + 0.2, y: exy + 0.3, w: exw - 0.4, h: 0.55, fontFace: HF, fontSize: 15, bold: true, color: NAVY });
  s.addText(c[1], { x: x + 0.2, y: exy + 0.85, w: exw - 0.4, h: 0.5, fontFace: BF, fontSize: 12, color: INK });
});

// =================================================================
// SLIDE 8 — CLOSING
// =================================================================
s = p.addSlide();
s.background = { color: NAVY };
s.addShape(p.ShapeType.ellipse, { x: 11.6, y: -1.3, w: 3.8, h: 3.8, fill: { color: STEEL }, line: { type: "none" } });
s.addShape(p.ShapeType.rect, { x: 0, y: 0, w: W, h: 0.12, fill: { color: ORANGE }, line: { type: "none" } });
s.addText("สรุป", { x: 0.9, y: 1.2, w: 11, h: 0.4, fontFace: HF, fontSize: 15, bold: true, color: ORANGE });
s.addText("ครั้งแรกที่เรามองเห็นการใช้งานไดร์\nได้ครบทั้งวงจร", { x: 0.9, y: 1.6, w: 11.3, h: 1.6, fontFace: HF, fontSize: 33, bold: true, color: WHITE, lineSpacingMultiple: 1.05 });
const close = [
  ["เห็นหน้างานรายวัน", "รู้ว่าไดร์ถูกเบิกไปลงบล็อกไหนทุกวัน", ORANGE],
  ["มั่นใจว่าผลิตตรงแผน", "เช็คความพร้อมก่อนเริ่มงานได้ทุกใบ", TEAL],
  ["ย้อนรอยปัญหาได้", "ตามจากใบเคลมกลับไปถึงการผลิต", SKY],
  ["ดูแลไดร์รายตัว", "รู้ประวัติและน้ำหนักงานของไดร์แต่ละตัว", GOLD],
];
let cx2 = 0.9, cw2 = 2.78, cg2 = 0.22, cyy = 3.55;
close.forEach((c, i) => {
  const x = cx2 + i * (cw2 + cg2);
  card(s, x, cyy, cw2, 2.05, STEEL);
  s.addShape(p.ShapeType.rect, { x, y: cyy, w: cw2, h: 0.13, fill: { color: c[2] }, line: { type: "none" } });
  s.addText(c[0], { x: x + 0.25, y: cyy + 0.35, w: cw2 - 0.5, h: 0.8, fontFace: HF, fontSize: 16.5, bold: true, color: WHITE });
  s.addText(c[1], { x: x + 0.25, y: cyy + 1.15, w: cw2 - 0.5, h: 0.8, fontFace: BF, fontSize: 13, color: LIGHTBLUE });
});
s.addText("พร้อมใช้งานแล้วที่เมนู Die Tracking  ·  ข้อมูลจากโรงงาน W และ P",
  { x: 0.9, y: 6.1, w: 11.5, h: 0.5, fontFace: BF, fontSize: 15, italic: true, color: LIGHTBLUE });

p.writeFile({ fileName: "C:/xampp/htdocs/menam-workflow/docs/die-tracking/die-tracking-overview.pptx" })
  .then(f => console.log("WROTE", f));
