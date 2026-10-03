<?php require __DIR__.'/auth.php'; require_auth();
$db=app_db();$activeRequests=[];
if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='service_requests'")->fetchColumn())$activeRequests=$db->query("SELECT id,client,address,service,request_type,stage,colleague,updated_at FROM service_requests WHERE stage NOT IN ('Приключена','Отказана','Неприета оферта','Изпълнена','Отразена в настъпили промени') ORDER BY updated_at DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
function info_e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function info_dt($v):string{if(!$v)return '—';try{return (new DateTimeImmutable((string)$v,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Sofia'))->format('d-m-Y H:i');}catch(Throwable $e){return (string)$v;}}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>INFO · Настъпили промени</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
:root{
  --ink:#1c2b33;
  --paper:#ffffff;
  --paper-raised:#f7f6f3;
  --line:#d3d0c8;
  --line-strong:#a9a59c;
  --active:#2f6f4e;
  --disconnected:#a3352a;
  --moved:#8a6d33;
  --muted:#6b6a63;
  --mono:"IBM Plex Mono","SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;
  --serif:"Source Serif Pro","Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
  --sans:"IBM Plex Sans","Segoe UI",Helvetica,Arial,sans-serif;
}

*{box-sizing:border-box;}

html,body{height:100%;}

body{
  margin:0;
  min-height:100vh;
  background:var(--paper);
  color:var(--ink);
  font-family:Inter,"Segoe UI",Arial,sans-serif;
  padding:0 0 80px;
}

.wrap{
  max-width:none;
  width:100%;
  margin:0;
  padding:32px 40px 0;
}

.portal-header{
  background:#183346;
  color:#fff;
  padding:17px max(24px,calc((100vw - 1100px)/2));
  display:flex;
  justify-content:space-between;
  align-items:center;
}
.portal-header strong{font-size:18px}
.portal-header a{color:#fff;text-decoration:none;font-size:14px;font-weight:700}
.report-head{
  border-bottom:2px solid var(--ink);
  padding-bottom:18px;
  margin-bottom:26px;
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  gap:20px;
  flex-wrap:wrap;
}

h1{
  font-family:Inter,"Segoe UI",Arial,sans-serif;
  font-weight:700;
  font-size:30px;
  margin:0 0 4px;
  letter-spacing:-0.01em;
}

header p{
  margin:0;
  color:var(--muted);
  font-size:14px;
  max-width:52ch;
}

.counts{
  display:flex;
  gap:22px;
  font-family:var(--mono);
  font-size:13px;
}

.counts div{
  display:flex;
  flex-direction:column;
  align-items:flex-end;
}

.counts span.n{
  font-size:20px;
  font-weight:600;
  line-height:1.1;
}

.counts .active .n{color:var(--active);}
.counts .disconnected .n{color:var(--disconnected);}
.counts .moved .n{color:var(--moved);}

.toolbar{
  display:flex;
  gap:10px;
  margin-bottom:16px;
  flex-wrap:wrap;
  align-items:center;
}

.toolbar input[type="text"],
.toolbar select{
  font-family:var(--sans);
  font-size:14px;
  padding:9px 12px;
  border:1px solid var(--line-strong);
  background:var(--paper-raised);
  color:var(--ink);
  border-radius:2px;
}

.toolbar input[type="text"]{
  flex:1;
  min-width:180px;
}

.spacer{flex:1;}

button{
  font-family:var(--sans);
  font-size:14px;
  padding:9px 16px;
  border:1px solid var(--ink);
  background:var(--ink);
  color:var(--paper-raised);
  cursor:pointer;
  border-radius:2px;
}

button.secondary{
  background:transparent;
  color:var(--ink);
}

button.ghost{
  border-color:var(--line-strong);
  background:transparent;
  color:var(--muted);
  padding:6px 10px;
  font-size:13px;
}

button:hover{opacity:0.85;}

button:focus-visible,
input:focus-visible,
select:focus-visible{
  outline:2px solid var(--ink);
  outline-offset:1px;
}

table{
  width:100%;
  border-collapse:collapse;
  background:var(--paper-raised);
}

thead th{
  text-align:left;
  font-weight:600;
  font-size:12.5px;
  color:var(--muted);
  padding:10px 12px;
  border-bottom:2px solid var(--ink);
}

tbody td{
  padding:12px;
  border-bottom:1px solid var(--line);
  font-size:14.5px;
  vertical-align:middle;
}

tbody tr:hover{
  background:rgba(0,0,0,0.02);
}

td.num{
  font-family:var(--mono);
  font-size:14px;
}

.status-tag{
  display:inline-flex;
  align-items:center;
  gap:7px;
  font-size:13px;
  padding-left:9px;
  border-left:3px solid var(--muted);
}

.status-tag.active{
  border-color:var(--active);
  color:var(--active);
}

.status-tag.disconnected{
  border-color:var(--disconnected);
  color:var(--disconnected);
}

.status-tag.moved{
  border-color:var(--moved);
  color:var(--moved);
}

.row-actions{
  display:flex;
  gap:6px;
  justify-content:flex-end;
}

td input[type="text"],
td select.status-edit{
  width:100%;
  font-family:var(--sans);
  font-size:14px;
  padding:6px 8px;
  border:1px solid var(--line-strong);
  border-radius:2px;
  background:#fff;
}

td.num input{
  font-family:var(--mono);
}

.empty{
  text-align:center;
  padding:50px 20px;
  color:var(--muted);
  font-size:14.5px;
}

.new-row td{
  background:#f2f1ec;
}

.date-field{
  position:relative;
}

#edit-date{
  cursor:pointer;
  background:#fff;
}

.calendar-popup{
  position:absolute;
  z-index:20;
  background:#fff;
  border:1px solid var(--line-strong);
  box-shadow:0 4px 14px rgba(0,0,0,0.14);
  border-radius:2px;
  padding:10px;
  width:230px;
  font-size:13px;
  display:none;
}

.calendar-popup.open{
  display:block;
}

.cal-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:8px;
  font-family:var(--mono);
}

.cal-head button{
  padding:2px 8px;
  font-size:13px;
  background:transparent;
  color:var(--ink);
  border:1px solid var(--line-strong);
}

.cal-grid{
  display:grid;
  grid-template-columns:repeat(7,1fr);
  gap:2px;
  text-align:center;
}

.cal-grid .dow{
  color:var(--muted);
  font-size:11px;
  padding:2px 0;
}

.cal-grid .day{
  padding:5px 0;
  cursor:pointer;
  border-radius:2px;
}

.cal-grid .day:hover{
  background:var(--paper);
}

.cal-grid .day.today{
  border:1px solid var(--line-strong);
}

.cal-grid .day.selected{
  background:var(--ink);
  color:#fff;
}

.cal-grid .blank{
  visibility:hidden;
}

footer{
  margin-top:14px;
  color:var(--muted);
  font-size:12.5px;
}

@media(max-width:800px){
  body{
    padding:20px 12px 50px;
  }

  table{
    min-width:900px;
  }

  .wrap{
    overflow-x:auto;
  }

  .report-head{
    align-items:flex-start;
  }
}
.home-link{display:inline-block;margin-top:8px;color:#246b8d;text-decoration:none;font-weight:700;font-size:14px}.home-link:hover{text-decoration:underline}</style>
<link rel="stylesheet" href="/assets/tim-theme.css?v=20261003"></head>

<body>

<header class="portal-header"><strong>Настъпили промени</strong><a href="/">← Начало</a></header>

<div class="wrap">

<header class="report-head">

  <div>
    <h1>Настъпили промени</h1>
  </div>

  <div class="counts">

    <div class="active">
      <span class="n" id="count-active">0</span>
      нови
    </div>

    <div class="disconnected">
      <span class="n" id="count-disconnected">0</span>
      изключени
    </div>

    <div class="moved">
      <span class="n" id="count-moved">0</span>
      преместени
    </div>

  </div>

</header>

<div class="toolbar">

  <input
    type="text"
    id="search"
    placeholder="Търсене по номер, адрес или лице…"
  >

  <select id="filter-status">
    <option value="all">Всички статуси</option>
    <option value="active">НОВ</option>
    <option value="disconnected">Изключен</option>
    <option value="moved">Преместен</option>
  </select>

  <div class="spacer"></div>

  <button class="secondary" id="btn-excel">
    Excel
  </button>

  <button id="btn-new">
    + Нов обект
  </button>

</div>

<table>

<thead>
<tr>
  <th style="width:120px">Дата</th>
  <th style="width:130px">Статус</th>
  <th style="width:150px">Номер на обект</th>
  <th>Име / Фирма</th>
  <th style="width:130px">ЕИК</th>
  <th>Представляващ</th>
  <th>Адрес</th>
  <th style="width:140px">Телефон</th>
  <th>Оторизирани лица</th>
  <th style="width:90px"></th>
</tr>
</thead>

<tbody id="tbody"></tbody>

</table>

<div
  class="empty"
  id="empty-msg"
  style="display:none;"
>
  Няма обекти. Добавете първия с бутона "Нов обект".
</div>

<footer id="save-status">
  Данните се зареждат от сървъра.
</footer>

</div>

<div class="calendar-popup" id="calendar-popup"></div>


<script>

const STATUS_LABELS = {
  active: "НОВ",
  disconnected: "Изключен",
  moved: "Преместен"
};

let objects = [];
let editingId = null;
let addingNew = false;

const tbody = document.getElementById("tbody");
const emptyMsg = document.getElementById("empty-msg");
const searchInput = document.getElementById("search");
const filterSelect = document.getElementById("filter-status");
const saveStatus = document.getElementById("save-status");


/* =========================
   ID
========================= */

function uid(){
  return "o" + Date.now() + Math.floor(Math.random() * 1000);
}


/* =========================
   LOAD FROM SERVER
========================= */

async function loadData(){

  try{

    saveStatus.textContent = "Зареждане...";

    const res = await fetch("api.php", {
      method: "GET",
      cache: "no-store"
    });

    if(!res.ok){
      throw new Error("HTTP " + res.status);
    }

    const data = await res.json();

    if(Array.isArray(data)){
      objects = data;
    }else{
      objects = [];
    }

    saveStatus.textContent =
      "Данните са заредени от сървъра.";

  }catch(e){

    console.error("Грешка при зареждане:", e);

    objects = [];

    saveStatus.textContent =
      "Грешка при зареждане на данните.";

  }

  render();
}


/* =========================
   SAVE TO SERVER
========================= */

async function saveData(){

  try{

    saveStatus.textContent = "Записване...";

    const res = await fetch("api.php", {

      method: "POST",

      headers: {
        "Content-Type": "application/json"
      },

      body: JSON.stringify(objects)

    });

    if(!res.ok){
      throw new Error("HTTP " + res.status);
    }

    const result = await res.json();

    if(!result.success){
      throw new Error("Server refused save");
    }

    saveStatus.textContent =
      "Запазено на сървъра.";

  }catch(e){

    console.error("Грешка при запис:", e);

    saveStatus.textContent =
      "ГРЕШКА: данните не бяха записани.";

    alert(
      "Възникна грешка при записването на данните на сървъра."
    );

  }

  setTimeout(()=>{

    saveStatus.textContent =
      "Данните се запазват автоматично на сървъра.";

  },1800);
}


/* =========================
   FILTER
========================= */

function matchesFilters(o){

  const q =
    searchInput.value
      .trim()
      .toLowerCase();

  const statusOk =
    filterSelect.value === "all" ||
    o.status === filterSelect.value;

  const text =
    (o.number || "") + " " +
    (o.client_name || "") + " " +
    (o.company_id || "") + " " +
    (o.representative || "") + " " +
    (o.address || "") + " " +
    (o.phone || "") + " " +
    (o.persons || "") + " " +
    formatDate(o.date);

  return statusOk &&
    (
      q === "" ||
      text.toLowerCase().includes(q)
    );
}


/* =========================
   COUNTERS
========================= */

function updateCounts(){

  document.getElementById("count-active").textContent =
    objects.filter(o => o.status === "active").length;

  document.getElementById("count-disconnected").textContent =
    objects.filter(o => o.status === "disconnected").length;

  document.getElementById("count-moved").textContent =
    objects.filter(o => o.status === "moved").length;
}


/* =========================
   STATUS
========================= */

function statusTag(status){

  return `
    <span class="status-tag ${status}">
      ${STATUS_LABELS[status] || status}
    </span>
  `;
}


/* =========================
   RENDER
========================= */

function render(){

  updateCounts();

  tbody.innerHTML = "";

  const visible =
    objects.filter(matchesFilters);

  emptyMsg.style.display =
    (
      objects.length === 0 &&
      !addingNew
    )
    ? "block"
    : "none";


  if(addingNew){

    tbody.appendChild(
      buildEditRow(
        {
          id:null,
          status:"active",
          number:"",
          address:"",
          persons:"",
          date:new Date()
            .toISOString()
            .slice(0,10)
        },
        true
      )
    );

  }


  visible.forEach(o => {

    if(editingId === o.id){

      tbody.appendChild(
        buildEditRow(o,false)
      );

    }else{

      tbody.appendChild(
        buildViewRow(o)
      );

    }

  });

}


/* =========================
   VIEW ROW
========================= */

function buildViewRow(o){

  const tr =
    document.createElement("tr");

  tr.innerHTML = `

    <td class="num">
      ${formatDate(o.date)}
    </td>

    <td>
      ${statusTag(o.status)}
    </td>

    <td class="num">
      ${escapeHtml(o.number)}
    </td>

    <td>${escapeHtml(o.client_name || "")}</td>
    <td class="num">${escapeHtml(o.company_id || "")}</td>
    <td>${escapeHtml(o.representative || "")}</td>
    <td>${escapeHtml(o.address)}</td>
    <td>${escapeHtml(o.phone || "")}</td>
    <td>${escapeHtml(o.persons)}</td>

    <td>

      <div class="row-actions">

        <button
          class="ghost"
          data-action="edit"
          data-id="${escapeAttr(o.id)}"
        >
          Ред.
        </button>

        <button
          class="ghost"
          data-action="delete"
          data-id="${escapeAttr(o.id)}"
        >
          Изтр.
        </button>

      </div>

    </td>

  `;

  return tr;
}


/* =========================
   EDIT ROW
========================= */

function buildEditRow(o,isNew){

  const tr =
    document.createElement("tr");

  tr.className = "new-row";


  const options =
    Object.keys(STATUS_LABELS)
      .map(k => `

        <option
          value="${k}"
          ${o.status === k ? "selected" : ""}
        >
          ${STATUS_LABELS[k]}
        </option>

      `)
      .join("");


  tr.innerHTML = `

    <td>

      <div class="date-field">

        <input
          type="text"
          id="edit-date"
          readonly
          value="${o.date ? isoToDisplay(o.date) : ''}"
          data-iso="${escapeAttr(o.date || '')}"
          placeholder="дд/мм/гггг"
        >

      </div>

    </td>


    <td>

      <select
        class="status-edit"
        id="edit-status"
      >

        ${options}

      </select>

    </td>


    <td class="num">

      <input
        type="text"
        id="edit-number"
        value="${escapeAttr(o.number)}"
        placeholder="напр. 0142"
        maxlength="4"
      >

    </td>


    <td><input type="text" id="edit-client-name" value="${escapeAttr(o.client_name || '')}" placeholder="име / фирма"></td>
    <td><input type="text" id="edit-company-id" value="${escapeAttr(o.company_id || '')}" placeholder="ЕИК"></td>
    <td><input type="text" id="edit-representative" value="${escapeAttr(o.representative || '')}" placeholder="представляващ"></td>
    <td><input type="text" id="edit-address" value="${escapeAttr(o.address)}" placeholder="адрес"></td>
    <td><input type="text" id="edit-phone" value="${escapeAttr(o.phone || '')}" placeholder="телефон"></td>
    <td><input type="text" id="edit-persons" value="${escapeAttr(o.persons)}" placeholder="имена, разделени със запетая"></td>


    <td>

      <div class="row-actions">

        <button
          data-action="save"
          data-id="${escapeAttr(o.id || '')}"
          data-new="${isNew}"
        >
          ✓
        </button>

        <button
          class="ghost"
          data-action="cancel"
        >
          ✕
        </button>

      </div>

    </td>

  `;

  return tr;
}


/* =========================
   ESCAPE
========================= */

function escapeHtml(s){

  return String(s || "")
    .replace(
      /[&<>"']/g,
      c => ({
        "&":"&amp;",
        "<":"&lt;",
        ">":"&gt;",
        '"':"&quot;",
        "'":"&#39;"
      }[c])
    );

}

function escapeAttr(s){
  return escapeHtml(s);
}


/* =========================
   DATE
========================= */

function formatDate(iso){

  if(!iso){
    return "—";
  }

  const [y,m,d] =
    iso.split("-");

  if(!y || !m || !d){
    return escapeHtml(iso);
  }

  return `${d}-${m}-${y}`;
}


function isoToDisplay(iso){

  const [y,m,d] =
    iso.split("-");

  return `${d}-${m}-${y}`;
}


/* =========================
   TABLE BUTTONS
========================= */

tbody.addEventListener(
  "click",
  async (e) => {

    const btn =
      e.target.closest("button");

    if(!btn) return;

    const action =
      btn.dataset.action;

    const id =
      btn.dataset.id;


    /* EDIT */

    if(action === "edit"){

      editingId = id;
      addingNew = false;

      render();

      return;
    }


    /* DELETE */

    if(action === "delete"){

      if(
        confirm(
          "Да се изтрие ли този обект?"
        )
      ){

        objects =
          objects.filter(
            o => o.id !== id
          );

        await saveData();

        render();
      }

      return;
    }


    /* CANCEL */

    if(action === "cancel"){

      editingId = null;
      addingNew = false;

      render();

      return;
    }


    /* SAVE */

    if(action === "save"){

      const status =
        document.getElementById(
          "edit-status"
        ).value;

      const number =
        document.getElementById(
          "edit-number"
        ).value.trim();

      const client_name = document.getElementById("edit-client-name").value.trim();
      const company_id = document.getElementById("edit-company-id").value.trim();
      const representative = document.getElementById("edit-representative").value.trim();
      const address =
        document.getElementById(
          "edit-address"
        ).value.trim();
      const phone = document.getElementById("edit-phone").value.trim();

      const persons =
        document.getElementById(
          "edit-persons"
        ).value.trim();

      const date =
        document.getElementById(
          "edit-date"
        ).dataset.iso || "";


      if(!number || !address){

        alert(
          "Моля, попълнете поне номер на обект и адрес."
        );

        return;
      }


      if(number.length !== 4){

        alert(
          "Номерът на обекта трябва да е точно 4 символа."
        );

        return;
      }


      if(btn.dataset.new === "true"){

        objects.push({

          id:uid(),
          status,
          number,
          client_name,
          company_id,
          representative,
          address,
          phone,
          persons,
          date

        });

        addingNew = false;

      }else{

        const o =
          objects.find(
            o => o.id === id
          );

        if(o){

          Object.assign(
            o,
            {
              status,
              number,
              client_name,
              company_id,
              representative,
              address,
              phone,
              persons,
              date
            }
          );

        }

        editingId = null;
      }


      await saveData();

      render();

    }

  }
);


/* =========================
   NEW OBJECT
========================= */

document
  .getElementById("btn-new")
  .addEventListener(
    "click",
    () => {

      editingId = null;
      addingNew = true;

      render();

      const el =
        document.getElementById(
          "edit-number"
        );

      if(el){
        el.focus();
      }

    }
  );


/* =========================
   SEARCH
========================= */

searchInput.addEventListener(
  "input",
  render
);


/* =========================
   FILTER
========================= */

filterSelect.addEventListener(
  "change",
  render
);


/* =========================
   EXCEL
========================= */

document
  .getElementById("btn-excel")
  .addEventListener(
    "click",
    () => {

      const rows =
        objects
          .filter(matchesFilters)
          .map(o => ({

            "Дата":
              formatDate(o.date),

            "Статус":
              STATUS_LABELS[o.status],

            "Номер на обект":
              o.number,

            "Адрес":
              o.address,

            "Оторизирани лица":
              o.persons

          }));


      const ws =
        XLSX.utils.json_to_sheet(
          rows
        );


      ws["!cols"] = [
        {wch:12},
        {wch:12},
        {wch:16},
        {wch:30},
        {wch:35}
      ];


      const wb =
        XLSX.utils.book_new();


      XLSX.utils.book_append_sheet(
        wb,
        ws,
        "Настъпили промени"
      );


      XLSX.writeFile(
        wb,
        "nastapili_promeni.xlsx"
      );

    }
  );


/* =========================
   LOAD
========================= */

loadData();


/* =========================
   CALENDAR
========================= */

const calPopup =
  document.getElementById(
    "calendar-popup"
  );

const DOW = [
  "Пн",
  "Вт",
  "Ср",
  "Чт",
  "Пт",
  "Сб",
  "Нд"
];

const MONTHS = [
  "януари",
  "февруари",
  "март",
  "април",
  "май",
  "юни",
  "юли",
  "август",
  "септември",
  "октомври",
  "ноември",
  "декември"
];

let calViewYear;
let calViewMonth;
let calTargetInput = null;


/* OPEN CALENDAR */

function openCalendar(input){

  calTargetInput = input;

  const iso =
    input.dataset.iso;

  const base =
    iso
      ? new Date(
          iso + "T00:00:00"
        )
      : new Date();

  calViewYear =
    base.getFullYear();

  calViewMonth =
    base.getMonth();

  renderCalendar();


  const r =
    input.getBoundingClientRect();

  calPopup.style.top =
    (
      window.scrollY +
      r.bottom +
      4
    ) + "px";

  calPopup.style.left =
    (
      window.scrollX +
      r.left
    ) + "px";


  calPopup.classList.add("open");
}


/* CLOSE */

function closeCalendar(){

  calPopup.classList.remove(
    "open"
  );

  calTargetInput = null;
}


/* RENDER CALENDAR */

function renderCalendar(){

  const iso =
    calTargetInput
      ? calTargetInput.dataset.iso
      : "";


  const todayIso =
    new Date()
      .toISOString()
      .slice(0,10);


  const firstOfMonth =
    new Date(
      calViewYear,
      calViewMonth,
      1
    );


  let startDow =
    firstOfMonth.getDay();


  startDow =
    (startDow + 6) % 7;


  const daysInMonth =
    new Date(
      calViewYear,
      calViewMonth + 1,
      0
    ).getDate();


  let cells = "";


  for(
    let i = 0;
    i < startDow;
    i++
  ){

    cells +=
      `<div class="blank"></div>`;

  }


  for(
    let d = 1;
    d <= daysInMonth;
    d++
  ){

    const cellIso =
      `${calViewYear}-${String(
        calViewMonth + 1
      ).padStart(2,"0")}-${String(
        d
      ).padStart(2,"0")}`;


    let cls = "day";


    if(cellIso === todayIso){
      cls += " today";
    }


    if(cellIso === iso){
      cls += " selected";
    }


    cells += `
      <div
        class="${cls}"
        data-iso="${cellIso}"
      >
        ${d}
      </div>
    `;

  }


  calPopup.innerHTML = `

    <div class="cal-head">

      <button
        type="button"
        data-nav="-1"
      >
        ‹
      </button>

      <span>
        ${MONTHS[calViewMonth]}
        ${calViewYear}
      </span>

      <button type="button" data-today="1">Днес</button>

      <button
        type="button"
        data-nav="1"
      >
        ›
      </button>

    </div>


    <div class="cal-grid">

      ${DOW
        .map(
          d => `<div class="dow">${d}</div>`
        )
        .join("")
      }

      ${cells}

    </div>
  `;
}


/* CALENDAR CLICK */

calPopup.addEventListener(
  "click",
  (e) => {

    const todayBtn = e.target.closest("[data-today]");
    if(todayBtn && calTargetInput){
      const n=new Date();
      const todayIso=n.getFullYear()+"-"+String(n.getMonth()+1).padStart(2,"0")+"-"+String(n.getDate()).padStart(2,"0");
      calTargetInput.dataset.iso=todayIso;
      calTargetInput.value=isoToDisplay(todayIso);
      closeCalendar();
      return;
    }

    const nav =
      e.target.closest(
        "[data-nav]"
      );


    if(nav){

      calViewMonth +=
        parseInt(
          nav.dataset.nav,
          10
        );


      if(calViewMonth < 0){

        calViewMonth = 11;
        calViewYear--;

      }


      if(calViewMonth > 11){

        calViewMonth = 0;
        calViewYear++;

      }


      renderCalendar();

      return;
    }


    const day =
      e.target.closest(
        ".day"
      );


    if(
      day &&
      calTargetInput
    ){

      const iso =
        day.dataset.iso;


      calTargetInput
        .dataset
        .iso = iso;


      calTargetInput.value =
        isoToDisplay(iso);


      closeCalendar();

    }

  }
);


/* OPEN ON DATE FIELD */

document.addEventListener(
  "focusin",
  (e) => {

    if(
      e.target &&
      e.target.id === "edit-date"
    ){

      openCalendar(e.target);

    }

  }
);


/* CLOSE */

document.addEventListener(
  "mousedown",
  (e) => {

    if(
      e.target &&
      e.target.id === "edit-date"
    ){
      return;
    }


    if(
      calPopup.contains(e.target)
    ){
      return;
    }


    closeCalendar();

  }
);

</script>

</body>
</html>
