<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth.php';

function iso(int $y,int $m,int $d): string { return sprintf('%04d-%02d-%02d',$y,$m,$d); }
function plusDays(string $date,int $days): string { return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d'); }
function orthodoxEaster(int $year): string {
    $a=$year%4;$b=$year%7;$c=$year%19;$d=(19*$c+15)%30;$e=(2*$a+4*$b-$d+34)%7;
    $month=intdiv($d+$e+114,31);$day=(($d+$e+114)%31)+1;
    return (new DateTimeImmutable(iso($year,$month,$day),new DateTimeZone('UTC')))->modify('+13 days')->format('Y-m-d');
}
function holidays(int $year): array {
    $h=[
      iso($year,1,1)=>'Нова година',iso($year,3,3)=>'Национален празник на България',
      iso($year,5,1)=>'Ден на труда',iso($year,5,6)=>'Гергьовден',
      iso($year,5,24)=>'Ден на светите братя Кирил и Методий',
      iso($year,9,6)=>'Съединението на България',iso($year,9,22)=>'Ден на независимостта',
      iso($year,12,24)=>'Бъдни вечер',iso($year,12,25)=>'Рождество Христово',
      iso($year,12,26)=>'Рождество Христово'
    ];
    $e=orthodoxEaster($year);
    $h[plusDays($e,-2)]='Разпети петък';$h[plusDays($e,-1)]='Велика събота';
    $h[$e]='Великден';$h[plusDays($e,1)]='Великден';
    return $h;
}
function nonWorking(int $year): array {
    return $year===2026 ? ['2026-01-02'=>'Неприсъствен ден по решение на Министерския съвет'] : [];
}
function addSubstitutes(int $year,array $holidays,array &$nonWorking): void {
    foreach([[1,1],[3,3],[5,1],[5,6],[5,24],[9,6],[9,22],[12,24],[12,25],[12,26]] as [$m,$d]) {
        $date=iso($year,$m,$d);$w=(int)(new DateTimeImmutable($date))->format('w');
        if($w!==0 && $w!==6) continue;
        $candidate=plusDays($date,1);
        while(true){
            $cw=(int)(new DateTimeImmutable($candidate))->format('w');
            if($cw===0||$cw===6||isset($holidays[$candidate])||isset($nonWorking[$candidate])){$candidate=plusDays($candidate,1);continue;}
            $nonWorking[$candidate]='Неприсъствен ден заради официален празник';break;
        }
    }
}
function fetchMonth(int $year,int $month): array {
    $url=sprintf('https://kik-info.com/spravochnik/calendar/%04d/%02d/00/',$year,$month);
    $ctx=stream_context_create(['http'=>['timeout'=>20,'user_agent'=>'GrafikCalendarSync/2.0']]);
    $html=@file_get_contents($url,false,$ctx);
    if($html===false) throw new RuntimeException("KIK не отговори за $year-$month");
    $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=preg_replace('/\s+/u',' ',$text);
    if(!preg_match('/(\d+)\s+работни дни/u',$text,$dm)||!preg_match('/(\d+)\s+часа/u',$text,$hm)) throw new RuntimeException("Не може да се прочете KIK за $year-$month");
    $days=(int)$dm[1];$hours=(int)$hm[1];
    if($hours!==$days*8) throw new RuntimeException("Невалидна норма $year-$month: $days/$hours");
    return [$days,$hours];
}
function syncYear(PDO $db,int $year): void {
    if($year<2020||$year>2099) throw new InvalidArgumentException('Невалидна година.');
    $months=[];
    for($m=1;$m<=12;$m++){[$d,$h]=fetchMonth($year,$m);$months[$m]=[$d,$h];echo sprintf("%04d-%02d: %d дни / %d часа\n",$year,$m,$d,$h);}
    $holidays=holidays($year);$nonWorking=nonWorking($year);addSubstitutes($year,$holidays,$nonWorking);
    $days=[];
    for($m=1;$m<=12;$m++){
        $calc=0;$last=cal_days_in_month(CAL_GREGORIAN,$m,$year);
        for($d=1;$d<=$last;$d++){
            $date=iso($year,$m,$d);$w=(int)(new DateTimeImmutable($date))->format('w');
            $holiday=isset($holidays[$date]);$nw=isset($nonWorking[$date]);
            $work=$w!==0&&$w!==6&&!$holiday&&!$nw;if($work)$calc++;
            $days[]=[$date,$work?1:0,$holiday?1:0,$holiday?$holidays[$date]:($nonWorking[$date]??null)];
        }
        if($calc!==$months[$m][0]) throw new RuntimeException(sprintf('%04d-%02d: изчислени %d, KIK %d',$year,$m,$calc,$months[$m][0]));
    }
    $db->beginTransaction();
    try{
        $del=$db->prepare('DELETE FROM staff_work_calendar WHERE year=?');$del->execute([$year]);
        $ins=$db->prepare("INSERT INTO staff_work_calendar(year,month,work_days,work_hours,source,updated_at) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP)");
        foreach($months as $m=>[$wd,$wh])$ins->execute([$year,$m,$wd,$wh,'KIK Info']);
        $del=$db->prepare("DELETE FROM staff_work_calendar_days WHERE work_date BETWEEN ? AND ?");$del->execute(["$year-01-01","$year-12-31"]);
        $ins=$db->prepare('INSERT INTO staff_work_calendar_days(work_date,is_workday,is_holiday,holiday_name) VALUES(?,?,?,?)');
        foreach($days as $row)$ins->execute($row);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    echo "OK: $year записана и валидирана спрямо KIK Info.\n";
}
$year=(int)($argv[1]??date('Y'));
syncYear(app_db(),$year);
