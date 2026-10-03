const express = require('express');
const pool = require('../db');

const {
  requireAuth,
  requireObjectAccess
} = require('../middleware/auth');

const router = express.Router();


// =====================================================
// HELPERS
// =====================================================

function validYearMonth(year, month) {

  return (
    Number.isInteger(year) &&
    Number.isInteger(month) &&
    year >= 2020 &&
    year <= 2100 &&
    month >= 1 &&
    month <= 12
  );
}


function monthStart(year, month) {

  return (
    String(year) +
    '-' +
    String(month).padStart(2, '0') +
    '-01'
  );
}


function monthEnd(year, month) {

  return new Date(
    Date.UTC(
      year,
      month,
      0
    )
  )
    .toISOString()
    .substring(0, 10);
}


// =====================================================
// CREATE / INITIALIZE REPORTED SCHEDULE
//
// POST
// /api/reported/:objectId/:year/:month/initialize
//
// При първо създаване:
// - създава reported_schedules
// - копира schedule_entries
// - основният график НЕ се променя
//
// Ако вече съществува:
// - НЕ го презаписва
// =====================================================

router.post(
  '/reported/:objectId/:year/:month/initialize',
  requireAuth,
  requireObjectAccess,
  async (req, res) => {

    const objectId =
      Number(req.params.objectId);

    const year =
      Number(req.params.year);

    const month =
      Number(req.params.month);


    if (
      !objectId ||
      !validYearMonth(
        year,
        month
      )
    ) {

      return res.status(400).json({
        error:
          'Невалиден обект, година или месец'
      });
    }


    const connection =
      await pool.getConnection();


    try {

      await connection.beginTransaction();


      const [existing] =
        await connection.query(
          `SELECT id
           FROM reported_schedules
           WHERE object_id = ?
             AND year = ?
             AND month = ?
           LIMIT 1`,
          [
            objectId,
            year,
            month
          ]
        );


      if (
        existing.length > 0
      ) {

        await connection.commit();

        return res.json({
          success: true,
          created: false,
          reported_schedule_id:
            Number(existing[0].id)
        });
      }


      const [scheduleResult] =
        await connection.query(
          `INSERT INTO reported_schedules
           (
             object_id,
             year,
             month
           )
           VALUES (?, ?, ?)`,
          [
            objectId,
            year,
            month
          ]
        );


      const reportedScheduleId =
        Number(
          scheduleResult.insertId
        );


      const startDate =
        monthStart(
          year,
          month
        );

      const endDate =
        monthEnd(
          year,
          month
        );


      /*
        Копираме само реално зададените записи.

        duration_hours:
        - ако реалната смяна е 4/8/12 часа,
          записваме реалната продължителност;
        - при друг тип/друга продължителност
          използваме 12 като начална стойност.

        На следващ етап ще ограничим автоматичната
        промяна само за подходящите работни смени.
      */

      const [entries] =
        await connection.query(
          `SELECT
             se.employee_id,
             se.work_date,
             se.shift_id,
             se.note,

             s.type,
             s.time_from,
             s.time_to

           FROM schedule_entries se

           LEFT JOIN shifts s
             ON s.id = se.shift_id
            AND s.object_id = se.object_id

           WHERE se.object_id = ?
             AND se.work_date >= ?
             AND se.work_date <= ?
             AND se.shift_id IS NOT NULL

           ORDER BY
             se.employee_id,
             se.work_date`,
          [
            objectId,
            startDate,
            endDate
          ]
        );


      let copied = 0;


      for (
        const entry
        of entries
      ) {

        let durationHours = 12;


        if (
          entry.time_from &&
          entry.time_to
        ) {

          const fromParts =
            String(
              entry.time_from
            ).split(':');

          const toParts =
            String(
              entry.time_to
            ).split(':');


          const fromMinutes =
            Number(fromParts[0]) * 60 +
            Number(fromParts[1]);


          let toMinutes =
            Number(toParts[0]) * 60 +
            Number(toParts[1]);


          if (
            toMinutes <=
            fromMinutes
          ) {

            toMinutes +=
              24 * 60;
          }


          const realHours =
            (
              toMinutes -
              fromMinutes
            ) / 60;


          if (
            realHours === 4 ||
            realHours === 8 ||
            realHours === 12
          ) {

            durationHours =
              realHours;
          }
        }


        await connection.query(
          `INSERT INTO reported_entries
           (
             reported_schedule_id,
             employee_id,
             work_date,
             shift_id,
             duration_hours,
             note
           )
           VALUES (?, ?, ?, ?, ?, ?)`,
          [
            reportedScheduleId,
            entry.employee_id,
            entry.work_date,
            entry.shift_id,
            durationHours,
            entry.note || ''
          ]
        );


        copied++;
      }


      await connection.commit();


      res.json({
        success: true,
        created: true,
        reported_schedule_id:
          reportedScheduleId,
        copied_entries:
          copied
      });


    } catch (error) {

      await connection.rollback();

      console.error(error);

      res.status(500).json({
        error:
          error.message
      });

    } finally {

      connection.release();
    }
  }
);


// =====================================================
// GET REPORTED SCHEDULE
//
// GET
// /api/reported/:objectId/:year/:month
// =====================================================

router.get(
  '/reported/:objectId/:year/:month',
  requireAuth,
  requireObjectAccess,
  async (req, res) => {

    try {

      const objectId =
        Number(req.params.objectId);

      const year =
        Number(req.params.year);

      const month =
        Number(req.params.month);


      if (
        !objectId ||
        !validYearMonth(
          year,
          month
        )
      ) {

        return res.status(400).json({
          error:
            'Невалиден обект, година или месец'
        });
      }


      const [schedules] =
        await pool.query(
          `SELECT
             id,
             object_id,
             year,
             month
           FROM reported_schedules
           WHERE object_id = ?
             AND year = ?
             AND month = ?
           LIMIT 1`,
          [
            objectId,
            year,
            month
          ]
        );


      if (
        schedules.length === 0
      ) {

        return res.status(404).json({
          error:
            'Отчетният график още не е създаден'
        });
      }


      const schedule =
        schedules[0];


      const [entries] =
        await pool.query(
          `SELECT
             re.id,
             re.reported_schedule_id,
             re.employee_id,

             DATE_FORMAT(
               re.work_date,
               '%Y-%m-%d'
             ) AS work_date,

             re.shift_id,
             re.duration_hours,
             re.note,

             s.code,
             s.name AS shift_name,
             s.type,
             s.time_from,
             s.time_to,
             s.counts_as_work_day

           FROM reported_entries re

           LEFT JOIN shifts s
             ON s.id = re.shift_id

           WHERE re.reported_schedule_id = ?

           ORDER BY
             re.employee_id,
             re.work_date`,
          [
            schedule.id
          ]
        );


      res.json({
        schedule,
        entries
      });


    } catch (error) {

      console.error(error);

      res.status(500).json({
        error:
          error.message
      });
    }
  }
);


// =====================================================
// CHANGE DURATION OF ONE REPORTED SHIFT
//
// PUT
// /api/reported/:objectId/entries/:entryId/duration
//
// BODY:
// {
//   "duration_hours": 4 | 8 | 12
// }
// =====================================================

router.put(
  '/reported/:objectId/entries/:entryId/duration',
  requireAuth,
  requireObjectAccess,
  async (req, res) => {

    try {

      const objectId =
        Number(req.params.objectId);

      const entryId =
        Number(req.params.entryId);

      const durationHours =
        Number(
          req.body.duration_hours
        );


      if (
        ![4, 8, 12].includes(
          durationHours
        )
      ) {

        return res.status(400).json({
          error:
            'Продължителността трябва да е 4, 8 или 12 часа'
        });
      }


      const [rows] =
        await pool.query(
          `SELECT
             re.id,
             s.type

           FROM reported_entries re

           INNER JOIN reported_schedules rs
             ON rs.id =
                re.reported_schedule_id

           LEFT JOIN shifts s
             ON s.id =
                re.shift_id

           WHERE re.id = ?
             AND rs.object_id = ?

           LIMIT 1`,
          [
            entryId,
            objectId
          ]
        );


      if (
        rows.length === 0
      ) {

        return res.status(404).json({
          error:
            'Отчетната смяна не е намерена'
        });
      }


      /*
        Продължителност 4/8/12 има смисъл
        за реален труд.

        П, отпуск и болничен не ги режем
        на 4/8/12.
      */

      if (
        rows[0].type !== 'work' &&
        rows[0].type !== 'duty'
      ) {

        return res.status(400).json({
          error:
            'Продължителността може да се променя само за работна смяна или дежурство'
        });
      }


      await pool.query(
        `UPDATE reported_entries
         SET duration_hours = ?
         WHERE id = ?`,
        [
          durationHours,
          entryId
        ]
      );


      res.json({
        success: true,
        id:
          entryId,
        duration_hours:
          durationHours
      });


    } catch (error) {

      console.error(error);

      res.status(500).json({
        error:
          error.message
      });
    }
  }
);




// =====================================================
// SAVE / CLEAR REPORTED CELL
//
// PUT /api/reported/:objectId/entry
//
// body:
// {
//   year,
//   month,
//   employee_id,
//   work_date,
//   shift_id
// }
//
// shift_id = null -> изтрива клетката
// =====================================================

router.put(
  '/reported/:objectId/entry',
  requireAuth,
  requireObjectAccess,
  async (req, res) => {

    try {

      const objectId =
        Number(req.params.objectId);

      const year =
        Number(req.body.year);

      const month =
        Number(req.body.month);

      const employeeId =
        Number(req.body.employee_id);

      const workDate =
        String(
          req.body.work_date || ''
        );

      const shiftId =
        req.body.shift_id === null
          ? null
          : Number(req.body.shift_id);


      if (
        !objectId ||
        !employeeId ||
        !validYearMonth(year, month) ||
        !workDate
      ) {

        return res.status(400).json({
          error:
            'Липсват или са невалидни данните за клетката'
        });
      }


      // -----------------------------------------------
      // Проверка: датата трябва да е от този месец
      // -----------------------------------------------

      if (
        workDate.substring(0, 7) !==
        (
          String(year) +
          '-' +
          String(month).padStart(2, '0')
        )
      ) {

        return res.status(400).json({
          error:
            'Датата не принадлежи на избрания месец'
        });
      }


      // -----------------------------------------------
      // Намираме отчетния график
      // -----------------------------------------------

      const [schedules] =
        await pool.query(
          `SELECT id
           FROM reported_schedules
           WHERE object_id = ?
             AND year = ?
             AND month = ?
           LIMIT 1`,
          [
            objectId,
            year,
            month
          ]
        );


      if (
        schedules.length === 0
      ) {

        return res.status(404).json({
          error:
            'Отчетният график още не е създаден'
        });
      }


      const reportedScheduleId =
        Number(
          schedules[0].id
        );


      // -----------------------------------------------
      // Проверка: служителят е от този обект
      // -----------------------------------------------

      const [employees] =
        await pool.query(
          `SELECT id
           FROM employees
           WHERE id = ?
             AND object_id = ?
           LIMIT 1`,
          [
            employeeId,
            objectId
          ]
        );


      if (
        employees.length === 0
      ) {

        return res.status(400).json({
          error:
            'Служителят не принадлежи на този обект'
        });
      }


      // -----------------------------------------------
      // CLEAR CELL
      // -----------------------------------------------

      if (shiftId === null) {

        await pool.query(
          `DELETE FROM reported_entries
           WHERE reported_schedule_id = ?
             AND employee_id = ?
             AND work_date = ?`,
          [
            reportedScheduleId,
            employeeId,
            workDate
          ]
        );


        return res.json({
          success: true,
          cleared: true
        });
      }


      // -----------------------------------------------
      // Проверка на смяната
      // -----------------------------------------------

      const [shiftRows] =
        await pool.query(
          `SELECT
             id,
             type,
             time_from,
             time_to

           FROM shifts

           WHERE id = ?
             AND object_id = ?

           LIMIT 1`,
          [
            shiftId,
            objectId
          ]
        );


      if (
        shiftRows.length === 0
      ) {

        return res.status(400).json({
          error:
            'Смяната не принадлежи на този обект'
        });
      }


      const shift =
        shiftRows[0];


      // -----------------------------------------------
      // Начална продължителност
      // -----------------------------------------------

      let durationHours = 12;


      if (
        shift.time_from &&
        shift.time_to
      ) {

        const fp =
          String(
            shift.time_from
          ).split(':');

        const tp =
          String(
            shift.time_to
          ).split(':');


        const fromMinutes =
          Number(fp[0]) * 60 +
          Number(fp[1]);


        let toMinutes =
          Number(tp[0]) * 60 +
          Number(tp[1]);


        if (
          toMinutes <=
          fromMinutes
        ) {

          toMinutes +=
            24 * 60;
        }


        const realHours =
          (
            toMinutes -
            fromMinutes
          ) / 60;


        if (
          [4, 8, 12].includes(
            realHours
          )
        ) {

          durationHours =
            realHours;
        }
      }


      // -----------------------------------------------
      // Ако клетката вече съществува:
      // сменяме shift_id и възстановяваме
      // началната продължителност.
      // -----------------------------------------------

      await pool.query(
        `INSERT INTO reported_entries
         (
           reported_schedule_id,
           employee_id,
           work_date,
           shift_id,
           duration_hours,
           note
         )
         VALUES (?, ?, ?, ?, ?, '')

         ON DUPLICATE KEY UPDATE
           shift_id =
             VALUES(shift_id),

           duration_hours =
             VALUES(duration_hours),

           updated_at =
             CURRENT_TIMESTAMP`,
        [
          reportedScheduleId,
          employeeId,
          workDate,
          shiftId,
          durationHours
        ]
      );


      res.json({
        success: true,
        shift_id:
          shiftId,
        duration_hours:
          durationHours
      });


    } catch (error) {

      console.error(error);

      res.status(500).json({
        error:
          error.message
      });
    }
  }
);




// =====================================================
// SMART REPORTED SCHEDULE GENERATOR
//
// POST /api/reported/:objectId/:year/:month/generate
//
// Цели:
// 1. Максимално близо до основния график.
// 2. П/О/Б не се местят.
// 3. Всеки ден: >= 1 дневна и >= 1 нощна.
// 4. Максимум: месечна норма + 4 часа.
// 5. Продължителности: 12/8/4; ME = 9 часа.
// =====================================================

router.post(
  '/reported/:objectId/:year/:month/generate',
  requireAuth,
  requireObjectAccess,
  async (req, res) => {

    const objectId = Number(req.params.objectId);
    const year = Number(req.params.year);
    const month = Number(req.params.month);

    if (
      !objectId ||
      !validYearMonth(year, month)
    ) {
      return res.status(400).json({
        error: 'Невалиден обект, година или месец'
      });
    }


    function minutes(value) {

      if (!value) {
        return null;
      }

      const parts =
        String(value).split(':');

      return (
        Number(parts[0]) * 60 +
        Number(parts[1])
      );
    }


    function realDuration(row) {

      const from =
        minutes(row.time_from);

      let to =
        minutes(row.time_to);

      if (
        from === null ||
        to === null
      ) {
        return 12;
      }

      if (to <= from) {
        to += 1440;
      }

      const h =
        (to - from) / 60;

      if ([4,8,9,12].includes(h)) {
        return h;
      }

      return 12;
    }


    function category(row) {

      if (
        row.type !== 'work' &&
        row.type !== 'duty'
      ) {
        return null;
      }

      const from =
        minutes(row.time_from);

      const to =
        minutes(row.time_to);

      if (
        from !== null &&
        to !== null &&
        (
          from >= 18 * 60 ||
          to <= 8 * 60
        ) &&
        to <= from
      ) {
        return 'night';
      }

      return 'day';
    }


    const connection =
      await pool.getConnection();


    try {

      await connection.beginTransaction();


      // ===============================================
      // НОРМА
      // ===============================================

      const [calendarRows] =
        await connection.query(
          `SELECT work_hours
           FROM work_calendar
           WHERE year = ?
             AND month = ?
           LIMIT 1`,
          [year, month]
        );


      if (calendarRows.length === 0) {
        throw new Error(
          'Няма месечна норма в работния календар'
        );
      }


      const normHours =
        Number(calendarRows[0].work_hours);

      const maxHours =
        normHours + 4;


      // ===============================================
      // ОТЧЕТЕН ГРАФИК
      // ===============================================

      const [scheduleRows] =
        await connection.query(
          `SELECT id
           FROM reported_schedules
           WHERE object_id = ?
             AND year = ?
             AND month = ?
           LIMIT 1`,
          [objectId, year, month]
        );


      if (scheduleRows.length === 0) {
        throw new Error(
          'Отчетният график още не е създаден'
        );
      }


      const reportedScheduleId =
        Number(scheduleRows[0].id);


      const startDate =
        monthStart(year, month);

      const endDate =
        monthEnd(year, month);


      // ===============================================
      // ВРЪЩАМЕ СЕ КЪМ ОСНОВНИЯ ГРАФИК
      // При всяко Генерирай започваме чисто.
      // ===============================================

      await connection.query(
        `DELETE FROM reported_entries
         WHERE reported_schedule_id = ?`,
        [reportedScheduleId]
      );


      const [baseRows] =
        await connection.query(
          `SELECT
             se.employee_id,
             se.work_date,
             se.shift_id,
             se.note,
             s.type,
             s.time_from,
             s.time_to

           FROM schedule_entries se

           INNER JOIN shifts s
             ON s.id = se.shift_id
            AND s.object_id = se.object_id

           WHERE se.object_id = ?
             AND se.work_date >= ?
             AND se.work_date <= ?
             AND se.shift_id IS NOT NULL

           ORDER BY
             se.work_date,
             se.employee_id`,
          [
            objectId,
            startDate,
            endDate
          ]
        );


      for (const row of baseRows) {

        let duration =
          realDuration(row);

        if (
          row.type === 'off' ||
          row.type === 'sick'
        ) {
          duration = 4;
        }

        if (row.type === 'vacation') {
          duration = 8;
        }


        await connection.query(
          `INSERT INTO reported_entries
           (
             reported_schedule_id,
             employee_id,
             work_date,
             shift_id,
             duration_hours,
             note
           )
           VALUES (?, ?, ?, ?, ?, ?)`,
          [
            reportedScheduleId,
            row.employee_id,
            row.work_date,
            row.shift_id,
            duration,
            row.note || ''
          ]
        );
      }


      // ===============================================
      // СМЕНИ НА ОБЕКТА
      // ===============================================

      const [shiftRows] =
        await connection.query(
          `SELECT
             id,
             code,
             name,
             type,
             time_from,
             time_to
           FROM shifts
           WHERE object_id = ?
             AND active = 1
           ORDER BY id`,
          [objectId]
        );


      const dayShifts =
        shiftRows.filter(
          row =>
            category(row) === 'day'
        );


      const nightShifts =
        shiftRows.filter(
          row =>
            category(row) === 'night'
        );


      if (
        dayShifts.length === 0 ||
        nightShifts.length === 0
      ) {
        throw new Error(
          'За обекта трябва да има поне една дневна и една нощна смяна'
        );
      }


      /*
        Предпочитаме:
        - дневна 12 часа;
        - нощна 12 часа.

        Така сме максимално близо
        до основния график.
      */

      const preferredDay =
        dayShifts.find(
          x => realDuration(x) === 12
        ) || dayShifts[0];


      const preferredNight =
        nightShifts.find(
          x => realDuration(x) === 12
        ) || nightShifts[0];


      const offShift =
        shiftRows.find(
          x => x.type === 'off'
        );


      if (!offShift) {
        throw new Error(
          'За обекта няма дефинирана смяна Почивка'
        );
      }


      // ===============================================
      // АКТИВНИ СЛУЖИТЕЛИ
      // ===============================================

      const [employees] =
        await connection.query(
          `SELECT
             id,
             employee_code,
             name
           FROM employees
           WHERE object_id = ?
             AND active = 1
           ORDER BY id`,
          [objectId]
        );


      // ===============================================
      // HELPER: текущо състояние
      // ===============================================

      async function loadState() {

        const [rows] =
          await connection.query(
            `SELECT
               re.id,
               re.employee_id,

               DATE_FORMAT(
                 re.work_date,
                 '%Y-%m-%d'
               ) AS work_date,

               re.shift_id,
               re.duration_hours,

               s.type,
               s.time_from,
               s.time_to,

               COALESCE(
                 wcd.is_workday,
                 0
               ) AS is_workday

             FROM reported_entries re

             INNER JOIN shifts s
               ON s.id = re.shift_id

             LEFT JOIN work_calendar_days wcd
               ON wcd.work_date =
                  re.work_date

             WHERE re.reported_schedule_id = ?

             ORDER BY
               re.work_date,
               re.employee_id`,
            [reportedScheduleId]
          );

        rows.forEach(
          row => {
            row.category =
              category(row);
          }
        );

        return rows;
      }


      function employeeHours(
        state,
        employeeId
      ) {

        let total = 0;

        for (const row of state) {

          if (
            Number(row.employee_id) !==
            Number(employeeId)
          ) {
            continue;
          }

          if (
            row.type === 'work' ||
            row.type === 'duty'
          ) {

            total +=
              Number(
                row.duration_hours || 0
              );

          } else if (
            row.type === 'vacation' &&
            Number(row.is_workday) === 1
          ) {

            total += 8;
          }
        }

        return total;
      }


      function entriesForDate(
        state,
        date
      ) {

        return state.filter(
          row =>
            row.work_date === date
        );
      }


      function employeeEntryOnDate(
        state,
        employeeId,
        date
      ) {

        return state.find(
          row =>
            Number(row.employee_id) ===
              Number(employeeId) &&
            row.work_date === date
        );
      }


      // ===============================================
      // ВСИЧКИ ДНИ В МЕСЕЦА
      // ===============================================

      const daysInMonth =
        new Date(
          Date.UTC(
            year,
            month,
            0
          )
        ).getUTCDate();


      const dates = [];

      for (
        let day = 1;
        day <= daysInMonth;
        day++
      ) {

        dates.push(
          String(year) +
          '-' +
          String(month).padStart(2,'0') +
          '-' +
          String(day).padStart(2,'0')
        );
      }


      let state =
        await loadState();

      let movedShifts = 0;
      let reducedShifts = 0;


      // ===============================================
      // 1. ПОКРИТИЕ ПО ДНИ
      // ===============================================

      for (const date of dates) {

        let dayRows =
          entriesForDate(
            state,
            date
          );


        let haveDay =
          dayRows.some(
            row =>
              row.category === 'day'
          );


        let haveNight =
          dayRows.some(
            row =>
              row.category === 'night'
          );


        // ---------------------------------------------
        // ЛИПСВА ДНЕВНА
        // ---------------------------------------------

        if (!haveDay) {

          /*
            Първо търсим свободен служител.
            П/О/Б не се пипат.
          */

          const free =
            employees
              .filter(
                emp => {

                  const current =
                    employeeEntryOnDate(
                      state,
                      emp.id,
                      date
                    );


                  return (
                    !current ||
                    current.type === 'off'
                  );
                }
              )
              .sort(
                (a,b) =>
                  employeeHours(state,a.id) -
                  employeeHours(state,b.id)
              )[0];


          if (free) {

            await connection.query(
              `INSERT INTO reported_entries
               (
                 reported_schedule_id,
                 employee_id,
                 work_date,
                 shift_id,
                 duration_hours,
                 note
               )
               VALUES (?, ?, ?, ?, ?, '')

               ON DUPLICATE KEY UPDATE
                 shift_id = VALUES(shift_id),
                 duration_hours = VALUES(duration_hours),
                 note = ''`,
              [
                reportedScheduleId,
                free.id,
                date,
                preferredDay.id,
                realDuration(
                  preferredDay
                )
              ]
            );

            movedShifts++;

          } else {

            /*
              Ако няма свободен:
              сменяме една излишна нощна
              само ако нощните са поне две.
            */

            const nights =
              dayRows.filter(
                row =>
                  row.category === 'night'
              );


            if (nights.length >= 2) {

              const change =
                nights
                  .slice()
                  .sort(
                    (a,b) =>
                      employeeHours(
                        state,
                        b.employee_id
                      ) -
                      employeeHours(
                        state,
                        a.employee_id
                      )
                  )[0];


              await connection.query(
                `UPDATE reported_entries
                 SET
                   shift_id = ?,
                   duration_hours = ?
                 WHERE id = ?`,
                [
                  preferredDay.id,
                  realDuration(
                    preferredDay
                  ),
                  change.id
                ]
              );

              movedShifts++;

            } else {

              throw new Error(
                date +
                ': няма възможност за дневна смяна'
              );
            }
          }


          state =
            await loadState();
        }


        dayRows =
          entriesForDate(
            state,
            date
          );


        haveNight =
          dayRows.some(
            row =>
              row.category === 'night'
          );


        // ---------------------------------------------
        // ЛИПСВА НОЩНА
        // ---------------------------------------------

        if (!haveNight) {

          const free =
            employees
              .filter(
                emp => {

                  const current =
                    employeeEntryOnDate(
                      state,
                      emp.id,
                      date
                    );


                  return (
                    !current ||
                    current.type === 'off'
                  );
                }
              )
              .sort(
                (a,b) =>
                  employeeHours(state,a.id) -
                  employeeHours(state,b.id)
              )[0];


          if (free) {

            await connection.query(
              `INSERT INTO reported_entries
               (
                 reported_schedule_id,
                 employee_id,
                 work_date,
                 shift_id,
                 duration_hours,
                 note
               )
               VALUES (?, ?, ?, ?, ?, '')

               ON DUPLICATE KEY UPDATE
                 shift_id = VALUES(shift_id),
                 duration_hours = VALUES(duration_hours),
                 note = ''`,
              [
                reportedScheduleId,
                free.id,
                date,
                preferredNight.id,
                realDuration(
                  preferredNight
                )
              ]
            );

            movedShifts++;

          } else {

            const days =
              dayRows.filter(
                row =>
                  row.category === 'day'
              );


            if (days.length >= 2) {

              const change =
                days
                  .slice()
                  .sort(
                    (a,b) =>
                      employeeHours(
                        state,
                        b.employee_id
                      ) -
                      employeeHours(
                        state,
                        a.employee_id
                      )
                  )[0];


              await connection.query(
                `UPDATE reported_entries
                 SET
                   shift_id = ?,
                   duration_hours = ?
                 WHERE id = ?`,
                [
                  preferredNight.id,
                  realDuration(
                    preferredNight
                  ),
                  change.id
                ]
              );

              movedShifts++;

            } else {

              throw new Error(
                date +
                ': няма възможност за нощна смяна'
              );
            }
          }


          state =
            await loadState();
        }
      }


      // ===============================================
      // 2. НАМАЛЯВАНЕ 12 -> 8 -> 4
      //
      // Намаляваме само ако за датата има
      // повече от една смяна от същата категория.
      // Така не режем единствената дневна/нощна.
      // ===============================================

      for (const employee of employees) {

        state =
          await loadState();


        let total =
          employeeHours(
            state,
            employee.id
          );


        if (total <= maxHours) {
          continue;
        }


        const candidates =
          state
            .filter(
              row =>
                Number(row.employee_id) ===
                  Number(employee.id) &&
                (
                  row.type === 'work' ||
                  row.type === 'duty'
                ) &&
                Number(
                  row.duration_hours
                ) === 12
            )
            .sort(
              (a,b) =>
                b.work_date.localeCompare(
                  a.work_date
                )
            );


        for (const row of candidates) {

          if (total <= maxHours) {
            break;
          }


          /*
            Смяната запазва типа си:
            Д1/Д2 остават дневни,
            Н остава нощна.

            Позволяваме:
            12 -> 8 -> 4
            дори ако е единствената смяна
            от този тип за деня.
          */

          let duration =
            Number(
              row.duration_hours
            );


          let changed = false;


          while (
            total > maxHours &&
            duration > 4
          ) {

            duration -= 4;
            total -= 4;
            changed = true;
          }


          if (changed) {

            await connection.query(
              `UPDATE reported_entries
               SET duration_hours = ?
               WHERE id = ?`,
              [
                duration,
                row.id
              ]
            );

            reducedShifts++;

            state =
              await loadState();
          }
        }
      }


      // ===============================================
      // 3. ПРЕХВЪРЛЯНЕ НА ЦЕЛИ СМЕНИ
      //
      // Само ако човек още е над maxHours.
      // Избираме служител без запис на тази дата.
      // ===============================================

      state =
        await loadState();


      for (const employee of employees) {

        let total =
          employeeHours(
            state,
            employee.id
          );


        if (total <= maxHours) {
          continue;
        }


        const candidates =
          state
            .filter(
              row =>
                Number(row.employee_id) ===
                  Number(employee.id) &&
                (
                  row.type === 'work' ||
                  row.type === 'duty'
                )
            )
            .sort(
              (a,b) =>
                b.work_date.localeCompare(
                  a.work_date
                )
            );


        for (const row of candidates) {

          if (total <= maxHours) {
            break;
          }


          const hours =
            Number(
              row.duration_hours
            );


          const targets =
            employees
              .filter(
                target => {

                  if (
                    Number(target.id) ===
                    Number(employee.id)
                  ) {
                    return false;
                  }


                  const targetEntry =
                    employeeEntryOnDate(
                      state,
                      target.id,
                      row.work_date
                    );


                  if (
                    targetEntry &&
                    targetEntry.type !== 'off'
                  ) {
                    return false;
                  }


                  const targetHours =
                    employeeHours(
                      state,
                      target.id
                    );


                  return (
                    targetHours +
                    hours <=
                    maxHours
                  );
                }
              )
              .sort(
                (a,b) =>
                  employeeHours(state,a.id) -
                  employeeHours(state,b.id)
              );


          if (targets.length === 0) {
            continue;
          }


          const target =
            targets[0];


          await connection.query(
            `UPDATE reported_entries
             SET employee_id = ?
             WHERE id = ?`,
            [
              target.id,
              row.id
            ]
          );


          movedShifts++;

          state =
            await loadState();

          total =
            employeeHours(
              state,
              employee.id
            );
        }
      }


      // ===============================================
      // 4. ФИНАЛНА ПРОВЕРКА
      // ===============================================

      state =
        await loadState();


      const problems = [];


      for (const date of dates) {

        const dayRows =
          entriesForDate(
            state,
            date
          );


        const haveDay =
          dayRows.some(
            row =>
              row.category === 'day'
          );


        const haveNight =
          dayRows.some(
            row =>
              row.category === 'night'
          );


        if (!haveDay) {
          problems.push(
            date +
            ': липсва дневна смяна'
          );
        }


        if (!haveNight) {
          problems.push(
            date +
            ': липсва нощна смяна'
          );
        }
      }


      const employeeResults = [];


      for (const employee of employees) {

        const total =
          employeeHours(
            state,
            employee.id
          );


        employeeResults.push({
          employee_id:
            employee.id,

          employee_code:
            employee.employee_code || '',

          employee_name:
            employee.name,

          hours:
            total,

          max_hours:
            maxHours
        });


        if (total > maxHours) {

          problems.push(
            employee.name +
            ': ' +
            total +
            ' ч. при максимум ' +
            maxHours +
            ' ч.'
          );
        }
      }


      if (problems.length > 0) {

        await connection.rollback();


        return res.status(409).json({
          error:
            'Не може да се генерира напълно валиден Отчетен график.',

          problems:
            problems
        });
      }


      await connection.commit();


      res.json({
        success: true,

        norm_hours:
          normHours,

        max_hours:
          maxHours,

        moved_shifts:
          movedShifts,

        reduced_shifts:
          reducedShifts,

        employees:
          employeeResults
      });


    } catch (error) {

      await connection.rollback();

      console.error(error);

      res.status(500).json({
        error:
          error.message
      });

    } finally {

      connection.release();
    }
  }
);




module.exports = router;
