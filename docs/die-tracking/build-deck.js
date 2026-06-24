const pptxgen = require("pptxgenjs");
const p = new pptxgen();
p.defineLayout({ name: "W", width: 13.333, height: 7.5 });
p.layout = "W";

// ---- Palette: Industrial Steel ----
const NAVY = "16314A";   // deep steel blue (dominant dark)
const STEEL = "274C68";  // mid steel
const ICE = "EAF1F6";    // light panel
const ORANGE = "F2933D"; // accent (molten/spark)
const TEAL = "2BA199";   // secondary accent
const INK = "1B2733";    // body text
const MUTE = "6B7C8C";   // muted
const WHITE = "FFFFFF";

const HF = "Trebuchet MS"; // header font
const BF = "Calibri";       // body font

const W = 13.333, H = 7.5;

// helper: rounded card
function card(s, x, y, w, h, fill, line) {
  s.addShape(p.ShapeType.roundRect, {
    x, y, w, h, rectRadius: 0.1,
    fill: { color: fill }, line: line ? { color: line, width: 1 } : { type: "none" },
    shadow: { type: "outer", color: "9AA9B5", blur: 6, offset: 2, angle: 90, opacity: 0.25 },
  });
}

function iconCircle(s, x, y, d, fill) {
  s.addShape(p.ShapeType.ellipse, { x, y, w: d, h: d, fill: { color: fill }, line: { type: "none" } });
}

// =================================================================
// SLIDE 1 — TITLE
// =================================================================
let s = p.addSlide();
s.background = { color: NAVY };
// motif: spark dots
s.addShape(p.ShapeType.ellipse, { x: 11.6, y: -1.2, w: 3.6, h: 3.6, fill: { color: STEEL }, line: { type: "none" } });
s.addShape(p.ShapeType.ellipse, { x: 12.6, y: 5.0, w: 3.2, h: 3.2, fill: { color: STEEL }, line: { type: "none" } });
s.addShape(p.ShapeType.rect, { x: 0, y: 6.95, w: W, h: 0.12, fill: { color: ORANGE }, line: { type: "none" } });

s.addText("DIE TRACKING", { x: 0.9, y: 2.0, w: 9, h: 0.6, fontFace: HF, fontSize: 18, color: ORANGE, bold: true, charSpacing: 6 });
s.addText("ระบบติดตามการใช้งานไดร์ (แม่พิมพ์ดึงลวด)", { x: 0.9, y: 2.6, w: 11, h: 1.1, fontFace: HF, fontSize: 40, color: WHITE, bold: true });
s.addText("สรุปโมดูลและฟังก์ชันทั้งหมดแบบเข้าใจง่าย", { x: 0.92, y: 3.8, w: 11, h: 0.6, fontFace: BF, fontSize: 18, color: "CADCFC" });

// quick badges
const badges = ["3 มุมมองหลัก", "ทะเบียนไดร์", "ประวัติรายตัว", "Analytics", "Export Excel"];
let bx = 0.92;
badges.forEach(b => {
  const bw = 0.32 + b.length * 0.14;
  s.addShape(p.ShapeType.roundRect, { x: bx, y: 4.7, w: bw, h: 0.5, rectRadius: 0.25, fill: { color: STEEL }, line: { color: ORANGE, width: 1 } });
  s.addText(b, { x: bx, y: 4.7, w: bw, h: 0.5, align: "center", fontFace: BF, fontSize: 12, color: WHITE });
  bx += bw + 0.2;
});

// =================================================================
// SLIDE 2 — ภาพรวมระบบ (มันคืออะไร)
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
s.addText("ระบบนี้ทำอะไร?", { x: 0.7, y: 0.5, w: 12, h: 0.7, fontFace: HF, fontSize: 34, color: NAVY, bold: true });
s.addText("ติดตามว่า “ไดร์” แต่ละตัวถูกเบิกไปใช้ที่ไหน ผลิตอะไร ใช้ไปกี่เมตร และตอนนี้อยู่ที่ไหน",
  { x: 0.72, y: 1.25, w: 12, h: 0.5, fontFace: BF, fontSize: 16, color: MUTE });

const concepts = [
  ["ไดร์ (Die)", "แม่พิมพ์สำหรับดึงลวดให้เล็กลงตามขนาด", ORANGE],
  ["Work Order (WO)", "ใบสั่งผลิต — บอกว่าจะผลิตงานอะไร", TEAL],
  ["Block / บล็อก", "ช่องในเครื่องดึงลวดที่ต้องใส่ไดร์", STEEL],
  ["เมตร (meter)", "ระยะลวดที่ดึงผ่านไดร์ = อายุการใช้งาน", ORANGE],
  ["กิโลกรัม (kg)", "น้ำหนักงานสำเร็จที่ไดร์ช่วยผลิต", TEAL],
  ["2 โรงงาน (W / P)", "ดึงข้อมูลจาก PostgreSQL 2 ฐาน เลือกแยกหรือรวมได้", STEEL],
];
let cx = 0.7, cy = 2.0, cw = 3.9, ch = 1.5, gap = 0.25;
concepts.forEach((c, i) => {
  const col = i % 3, row = Math.floor(i / 3);
  const x = cx + col * (cw + gap), y = cy + row * (ch + gap);
  card(s, x, y, cw, ch, ICE);
  s.addShape(p.ShapeType.rect, { x, y, w: 0.12, h: ch, fill: { color: c[2] }, line: { type: "none" } });
  s.addText(c[0], { x: x + 0.3, y: y + 0.18, w: cw - 0.5, h: 0.5, fontFace: HF, fontSize: 18, bold: true, color: NAVY });
  s.addText(c[1], { x: x + 0.3, y: y + 0.72, w: cw - 0.5, h: 0.7, fontFace: BF, fontSize: 13.5, color: INK });
});

// =================================================================
// SLIDE 3 — 3 มุมมองหลักของ Dashboard
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
s.addText("หน้า Dashboard — ดูได้ 3 มุมมอง", { x: 0.7, y: 0.5, w: 12, h: 0.7, fontFace: HF, fontSize: 34, color: NAVY, bold: true });
s.addText("เปิดหน้าเดียว สลับมุมมองได้ทันที พร้อมกราฟและตารางสรุป", { x: 0.72, y: 1.25, w: 12, h: 0.5, fontFace: BF, fontSize: 16, color: MUTE });

const views = [
  ["1", "ตามใบสั่งผลิต", "By Work Order", "พิมพ์เลข WO → เห็นว่าใช้ไดร์ตัวไหน บล็อกไหน ครบทุกบล็อกหรือยัง พร้อมเตือนปัญหา", ORANGE],
  ["2", "ตามวันที่", "By Date", "เลือกวันหรือช่วงวัน → เห็นการเบิกใช้ไดร์ทั้งหมด + ตารางความหนาแน่น (heatmap)", TEAL],
  ["3", "ตามสัปดาห์", "By Week", "เลือกปี/สัปดาห์ → สรุปการใช้ไดร์รายสัปดาห์ เทียบแนวโน้มได้", "7FA8C9"],
];
let vx = 0.7, vw = 3.95, vgap = 0.25, vy = 2.1, vh = 4.2;
views.forEach((v, i) => {
  const x = vx + i * (vw + vgap);
  card(s, x, vy, vw, vh, NAVY);
  iconCircle(s, x + vw / 2 - 0.55, vy + 0.45, 1.1, v[4]);
  s.addText(v[0], { x: x + vw / 2 - 0.55, y: vy + 0.45, w: 1.1, h: 1.1, align: "center", valign: "middle", fontFace: HF, fontSize: 40, bold: true, color: WHITE });
  s.addText(v[1], { x: x + 0.3, y: vy + 1.75, w: vw - 0.6, h: 0.5, align: "center", fontFace: HF, fontSize: 21, bold: true, color: WHITE });
  s.addText(v[2], { x: x + 0.3, y: vy + 2.25, w: vw - 0.6, h: 0.4, align: "center", fontFace: BF, fontSize: 13, italic: true, color: v[4] });
  s.addText(v[3], { x: x + 0.4, y: vy + 2.75, w: vw - 0.8, h: 1.3, align: "center", fontFace: BF, fontSize: 13.5, color: "CADCFC" });
});

// =================================================================
// SLIDE 4 — มุมมอง By Work Order (เด่น: ความครบถ้วน)
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
s.addText("เจาะมุมมอง: ตามใบสั่งผลิต (WO)", { x: 0.7, y: 0.5, w: 12, h: 0.7, fontFace: HF, fontSize: 32, color: NAVY, bold: true });
s.addText("จุดเด่นคือ “เช็คความพร้อม” ก่อนเริ่มผลิต — ไดร์ครบทุกบล็อกไหม", { x: 0.72, y: 1.22, w: 12, h: 0.5, fontFace: BF, fontSize: 16, color: MUTE });

const woFeatures = [
  ["ความครบถ้วน (Completeness)", "บอกว่าจากบล็อกที่ต้องมีทั้งหมด มีไดร์ครบกี่บล็อก ขาดบล็อกไหน"],
  ["ความพร้อม (Readiness)", "สรุปเป็น พร้อม / ต้องตรวจ / ขาด รายบล็อก เพื่อตัดสินใจเริ่มงาน"],
  ["รายการเตือน (Exceptions)", "เตือนอัตโนมัติ: ไดร์ขาด, สถานะไดร์ผิดปกติ, ขนาดบล็อกไม่ตรงไดร์, ใช้เมตรสูงผิดปกติ"],
  ["สรุปยอด (Summary)", "จำนวนรายการ, ไดร์ที่ใช้, เมตรรวม, จำนวน WO และแผนกที่เกี่ยวข้อง"],
];
let fy = 2.05;
woFeatures.forEach((f, i) => {
  const y = fy + i * 1.18;
  card(s, 0.7, y, 7.6, 1.0, ICE);
  iconCircle(s, 0.95, y + 0.25, 0.5, ORANGE);
  s.addText(String(i + 1), { x: 0.95, y: y + 0.25, w: 0.5, h: 0.5, align: "center", valign: "middle", fontFace: HF, fontSize: 20, bold: true, color: WHITE });
  s.addText(f[0], { x: 1.65, y: y + 0.12, w: 6.5, h: 0.4, fontFace: HF, fontSize: 16.5, bold: true, color: NAVY });
  s.addText(f[1], { x: 1.65, y: y + 0.5, w: 6.5, h: 0.45, fontFace: BF, fontSize: 12.5, color: INK });
});

// side panel — severity legend
card(s, 8.55, 2.05, 4.05, 4.72, NAVY);
s.addText("ระดับการเตือน", { x: 8.8, y: 2.25, w: 3.6, h: 0.5, fontFace: HF, fontSize: 18, bold: true, color: WHITE });
const sev = [["danger", "FF6B6B", "ไดร์ถูกตัดทิ้ง (SCRAP) — ห้ามใช้"], ["warning", "F2C94C", "ไดร์ขาด / สถานะผิด / ขนาดไม่ตรง"], ["info", "56CCF2", "เมตรสูง (≥ 5,000) ควรจับตา"]];
let sy = 2.95;
sev.forEach(v => {
  iconCircle(s, 8.8, sy, 0.35, v[1]);
  s.addText(v[0].toUpperCase(), { x: 9.3, y: sy - 0.05, w: 3.1, h: 0.4, fontFace: HF, fontSize: 14, bold: true, color: v[1] });
  s.addText(v[2], { x: 9.3, y: sy + 0.32, w: 3.1, h: 0.6, fontFace: BF, fontSize: 12, color: "CADCFC" });
  sy += 1.2;
});

// =================================================================
// SLIDE 5 — ทะเบียนไดร์ (Die Master) + Filters
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
s.addText("ทะเบียนไดร์ (Die Master)", { x: 0.7, y: 0.5, w: 12, h: 0.7, fontFace: HF, fontSize: 34, color: NAVY, bold: true });
s.addText("รายชื่อไดร์ทั้งหมดในระบบ ค้นหา/กรองได้หลายเงื่อนไข พร้อม กก. สะสมที่ไดร์เคยช่วยผลิต",
  { x: 0.72, y: 1.25, w: 12, h: 0.5, fontFace: BF, fontSize: 16, color: MUTE });

s.addText("กรองได้ตาม", { x: 0.7, y: 2.0, w: 6, h: 0.5, fontFace: HF, fontSize: 20, bold: true, color: STEEL });
const filters = [
  ["คำค้น", "เลขไดร์ / รายละเอียด"],
  ["สถานะ", "ใช้ได้ / ส่งซ่อม / ตัดทิ้ง"],
  ["หมวด", "หมวดหมู่ของไดร์ (category)"],
  ["ประเภท", "ประเภทอุปกรณ์ (เช่น ไดร์)"],
  ["ผู้ผลิต", "ผู้ผลิต/ผู้จำหน่ายไดร์"],
  ["รายละเอียด", "ข้อความบรรยายไดร์"],
];
let gx = 0.7, gy = 2.6, gw = 3.75, gh = 0.95, ggap = 0.2;
filters.forEach((f, i) => {
  const col = i % 2, row = Math.floor(i / 2);
  const x = gx + col * (gw + ggap), y = gy + row * (gh + ggap);
  card(s, x, y, gw, gh, ICE);
  s.addShape(p.ShapeType.rect, { x, y, w: 0.1, h: gh, fill: { color: TEAL }, line: { type: "none" } });
  s.addText(f[0], { x: x + 0.28, y: y + 0.13, w: gw - 0.4, h: 0.4, fontFace: HF, fontSize: 16, bold: true, color: NAVY });
  s.addText(f[1], { x: x + 0.28, y: y + 0.52, w: gw - 0.4, h: 0.35, fontFace: BF, fontSize: 12.5, color: INK });
});

// right column — autocomplete + supporting APIs
card(s, 8.6, 2.6, 4.05, 3.85, NAVY);
s.addText("ช่วยพิมพ์อัตโนมัติ", { x: 8.85, y: 2.8, w: 3.6, h: 0.5, fontFace: HF, fontSize: 18, bold: true, color: ORANGE });
s.addText("พิมพ์ไม่กี่ตัว ระบบเดาให้:", { x: 8.85, y: 3.3, w: 3.6, h: 0.4, fontFace: BF, fontSize: 13, color: "CADCFC" });
const sug = ["เลข Work Order", "เลขไดร์ (Die)", "รายละเอียดไดร์", "Heat No. (วัตถุดิบ)", "Coil No. (ม้วนลวด)"];
let suy = 3.8;
sug.forEach(t => {
  iconCircle(s, 8.9, suy + 0.05, 0.18, ORANGE);
  s.addText(t, { x: 9.2, y: suy - 0.07, w: 3.3, h: 0.4, fontFace: BF, fontSize: 14, color: WHITE });
  suy += 0.52;
});

// =================================================================
// SLIDE 6 — Die Profile (ประวัติไดร์รายตัว)
// =================================================================
s = p.addSlide();
s.background = { color: NAVY };
s.addText("ประวัติไดร์รายตัว (Die Profile)", { x: 0.7, y: 0.5, w: 12, h: 0.7, fontFace: HF, fontSize: 34, color: WHITE, bold: true });
s.addText("คลิกที่ไดร์ตัวใดก็ได้ เพื่อดูชีวิตทั้งหมดของไดร์ตัวนั้น", { x: 0.72, y: 1.25, w: 12, h: 0.5, fontFace: BF, fontSize: 16, color: "CADCFC" });

const stats = [
  ["เมตรสะสม", "ดึงลวดไปแล้วกี่เมตร", ORANGE],
  ["กก. ผลิตสะสม", "ช่วยผลิตงานกี่กิโล", TEAL],
  ["จำนวน WO", "ใช้กับใบสั่งผลิตกี่ใบ", "56CCF2"],
  ["จำนวนแผนก", "ถูกเบิกไปกี่แผนก", "F2C94C"],
];
let stx = 0.7, stw = 2.95, stgap = 0.22;
stats.forEach((st, i) => {
  const x = stx + i * (stw + stgap);
  card(s, x, 2.0, stw, 1.7, STEEL);
  s.addText(st[1], { x: x + 0.2, y: 2.25, w: stw - 0.4, h: 0.8, fontFace: BF, fontSize: 13, color: "CADCFC", valign: "top" });
  s.addText(st[0], { x: x + 0.2, y: 2.95, w: stw - 0.4, h: 0.6, fontFace: HF, fontSize: 19, bold: true, color: st[2] });
});

const profDetails = [
  ["ตำแหน่งปัจจุบัน", "ไดร์อยู่ที่แผนกไหน / ส่งซ่อม / อยู่ในคลัง"],
  ["Timeline ประวัติการเคลื่อนไหว", "ทุกครั้งที่เบิก–ใช้–คืน–ซ่อม เรียงตามเวลา"],
  ["Lifecycle (กก. สะสมตามเวลา)", "เส้นโค้งสะสมของงานที่ไดร์ช่วยผลิต"],
  ["สถานะสุขภาพ (Health)", "ดูจากสถานะจริงของไดร์ (ใช้ได้/ซ่อม/ทิ้ง) ไม่มี limit สมมติ"],
];
let py = 4.0;
profDetails.forEach((d, i) => {
  const col = i % 2, row = Math.floor(i / 2);
  const x = 0.7 + col * 6.1, y = py + row * 1.35;
  card(s, x, y, 5.85, 1.15, "1F3F5C");
  iconCircle(s, x + 0.25, y + 0.33, 0.5, TEAL);
  s.addText("✓", { x: x + 0.25, y: y + 0.3, w: 0.5, h: 0.5, align: "center", valign: "middle", fontFace: BF, fontSize: 20, bold: true, color: WHITE });
  s.addText(d[0], { x: x + 0.95, y: y + 0.18, w: 4.8, h: 0.45, fontFace: HF, fontSize: 16, bold: true, color: WHITE });
  s.addText(d[1], { x: x + 0.95, y: y + 0.6, w: 4.8, h: 0.45, fontFace: BF, fontSize: 12.5, color: "CADCFC" });
});

// =================================================================
// SLIDE 7 — Analytics widgets + ค้นหาพิเศษ
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
s.addText("วิเคราะห์ & ค้นหาพิเศษ", { x: 0.7, y: 0.5, w: 12, h: 0.7, fontFace: HF, fontSize: 34, color: NAVY, bold: true });
s.addText("วิดเจ็ตช่วยตัดสินใจ และเครื่องมือสืบย้อนกลับ", { x: 0.72, y: 1.25, w: 12, h: 0.5, fontFace: BF, fontSize: 16, color: MUTE });

const widgets = [
  ["แผนกใช้ไดร์สูงสุด", "Top Consumers", "แผนกไหนเบิกไดร์มากสุดในช่วงเวลา + กก. ที่ผลิต", ORANGE],
  ["ไดร์ผลิตงานสูงสุด", "Top Output Dies", "10 อันดับไดร์ที่ช่วยผลิต กก. มากสุด", TEAL],
  ["ไดร์นิ่ง (ไม่ถูกใช้)", "Idle Dies", "ไดร์ที่ไม่ถูกเบิกนานเกินกำหนด (เช่น 30 วัน)", STEEL],
  ["ไดร์อยู่ที่ไหน", "Current Location", "ตำแหน่งล่าสุดของไดร์แต่ละตัว กรองตามวันได้", ORANGE],
  ["สืบจากวัตถุดิบ", "Material Trace", "ใส่ Heat/Coil → ดูว่าใช้ไดร์ตัวไหนผลิต", TEAL],
  ["เทียบช่วงเวลา", "Compare", "เทียบยอดช่วงนี้กับช่วงก่อนหน้า + % เปลี่ยนแปลง", STEEL],
];
let wx = 0.7, wy = 2.0, ww = 3.9, wh = 2.15, wgap = 0.25;
widgets.forEach((wd, i) => {
  const col = i % 3, row = Math.floor(i / 3);
  const x = wx + col * (ww + wgap), y = wy + row * (wh + 0.25);
  card(s, x, y, ww, wh, ICE);
  s.addShape(p.ShapeType.rect, { x, y, w: ww, h: 0.13, fill: { color: wd[3] }, line: { type: "none" } });
  s.addText(wd[0], { x: x + 0.3, y: y + 0.35, w: ww - 0.6, h: 0.5, fontFace: HF, fontSize: 18, bold: true, color: NAVY });
  s.addText(wd[1], { x: x + 0.3, y: y + 0.88, w: ww - 0.6, h: 0.4, fontFace: BF, fontSize: 12.5, italic: true, color: wd[3] });
  s.addText(wd[2], { x: x + 0.3, y: y + 1.3, w: ww - 0.6, h: 0.75, fontFace: BF, fontSize: 13, color: INK });
});

// =================================================================
// SLIDE 8 — Export + ความปลอดภัย/ประสิทธิภาพ
// =================================================================
s = p.addSlide();
s.background = { color: WHITE };
s.addText("ส่งออกข้อมูล & เบื้องหลังระบบ", { x: 0.7, y: 0.5, w: 12, h: 0.7, fontFace: HF, fontSize: 34, color: NAVY, bold: true });
s.addText("ใช้งานง่ายหน้าบ้าน ปลอดภัยและเร็วหลังบ้าน", { x: 0.72, y: 1.25, w: 12, h: 0.5, fontFace: BF, fontSize: 16, color: MUTE });

// Export card (left, big)
card(s, 0.7, 2.0, 5.7, 4.6, NAVY);
iconCircle(s, 0.95, 2.3, 0.9, ORANGE);
s.addText("XLS", { x: 0.95, y: 2.3, w: 0.9, h: 0.9, align: "center", valign: "middle", fontFace: HF, fontSize: 16, bold: true, color: WHITE });
s.addText("ส่งออก Excel", { x: 2.0, y: 2.4, w: 4.2, h: 0.6, fontFace: HF, fontSize: 24, bold: true, color: WHITE });
s.addText("ดาวน์โหลดข้อมูลทุกมุมมอง (WO / วันที่ / สัปดาห์)", { x: 0.95, y: 3.5, w: 5.2, h: 0.5, fontFace: BF, fontSize: 14, color: "CADCFC" });
const exPts = ["เลือกมุมมองไหน ก็ส่งออกชุดนั้น", "เติม กก. งานสำเร็จต่อ WO ให้อัตโนมัติ", "รองรับทั้งโรงงาน W และ P", "ตั้งชื่อไฟล์ตามวันเวลาอัตโนมัติ"];
let ey = 4.1;
exPts.forEach(t => {
  iconCircle(s, 0.98, ey + 0.06, 0.2, ORANGE);
  s.addText(t, { x: 1.32, y: ey - 0.06, w: 4.9, h: 0.45, fontFace: BF, fontSize: 14, color: WHITE });
  ey += 0.6;
});

// Backend cards (right)
const backend = [
  ["จำกัดฐานข้อมูล", "ผู้ใช้เลือกได้เฉพาะโรงงาน W/P เท่านั้น ป้องกันชี้ไปฐานอื่น (เช่น ERP)", TEAL],
  ["แคชผลลัพธ์ 5 นาที", " query หนักถูกเก็บไว้ชั่วคราว เปิดซ้ำเร็วขึ้น ลดภาระฐานข้อมูล", ORANGE],
  ["รองรับหลายโรงงาน", "ดูแยกโรงงาน หรือรวม W+P พร้อมกันก็ได้", STEEL],
  ["แยกชั้นโค้ดชัดเจน", "Controller → Service → Repository ไม่มี SQL ปนใน Controller", TEAL],
];
let by = 2.0;
backend.forEach((b, i) => {
  const y = by + i * 1.18;
  card(s, 6.7, y, 5.95, 1.0, ICE);
  s.addShape(p.ShapeType.rect, { x: 6.7, y, w: 0.12, h: 1.0, fill: { color: b[2] }, line: { type: "none" } });
  s.addText(b[0], { x: 7.0, y: y + 0.13, w: 5.5, h: 0.4, fontFace: HF, fontSize: 16, bold: true, color: NAVY });
  s.addText(b[1], { x: 7.0, y: y + 0.52, w: 5.5, h: 0.45, fontFace: BF, fontSize: 12.5, color: INK });
});

// =================================================================
// SLIDE 9 — สรุปแผนผังโมดูล (closing)
// =================================================================
s = p.addSlide();
s.background = { color: NAVY };
s.addShape(p.ShapeType.rect, { x: 0, y: 0, w: W, h: 0.12, fill: { color: ORANGE }, line: { type: "none" } });
s.addText("สรุปภาพรวมโมดูล Die Tracking", { x: 0.7, y: 0.55, w: 12, h: 0.7, fontFace: HF, fontSize: 32, color: WHITE, bold: true });

const groups = [
  ["หน้าใช้งานหลัก", ["Dashboard 3 มุมมอง", "WO Detail (รายละเอียดงาน)", "Die Profile (ประวัติไดร์)", "Die Master (ทะเบียน)"], ORANGE],
  ["ค้นหา & สืบย้อน", ["ค้นหา WO / วันที่ / สัปดาห์", "เทียบช่วงเวลา (Compare)", "สืบจากวัตถุดิบ (Material)", "ช่วยพิมพ์อัตโนมัติ"], TEAL],
  ["วิเคราะห์", ["แผนกใช้ไดร์สูงสุด", "ไดร์ผลิตงานสูงสุด", "ไดร์นิ่ง (Idle)", "ตำแหน่งปัจจุบัน"], "56CCF2"],
  ["รองรับ & ส่งออก", ["ตัวกรอง (หมวด/สถานะ/ผู้ผลิต)", "ส่งออก Excel", "แคช 5 นาที", "หลายโรงงาน W+P"], "F2C94C"],
];
let ggx = 0.7, ggw = 2.97, gggap = 0.22;
groups.forEach((g, i) => {
  const x = ggx + i * (ggw + gggap);
  card(s, x, 1.7, ggw, 4.9, STEEL);
  s.addShape(p.ShapeType.rect, { x, y: 1.7, w: ggw, h: 0.6, fill: { color: g[2] }, line: { type: "none" } });
  s.addText(g[0], { x: x + 0.15, y: 1.7, w: ggw - 0.3, h: 0.6, align: "center", valign: "middle", fontFace: HF, fontSize: 16, bold: true, color: NAVY });
  let iy = 2.55;
  g[1].forEach(item => {
    iconCircle(s, x + 0.25, iy + 0.08, 0.16, g[2]);
    s.addText(item, { x: x + 0.5, y: iy - 0.05, w: ggw - 0.65, h: 0.55, fontFace: BF, fontSize: 12.5, color: WHITE, valign: "top" });
    iy += 1.0;
  });
});
s.addText("เปิดที่เมนู  /die-tracking  ·  ข้อมูลจาก PostgreSQL โรงงาน W และ P", { x: 0.7, y: 6.8, w: 12, h: 0.4, fontFace: BF, fontSize: 13, italic: true, color: "CADCFC" });

p.writeFile({ fileName: "C:/xampp/htdocs/menam-workflow/docs/die-tracking/die-tracking-overview.pptx" })
  .then(f => console.log("WROTE", f));
