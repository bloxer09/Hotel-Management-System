import { formatStaySchedule } from '@/Utils/stayDuration';

export default function StaySchedule({ booking }) {
    if (!booking?.check_in) {
        return <span className="text-slate-500">-</span>;
    }

    const schedule = formatStaySchedule(booking);

    return (
        <div className="leading-normal">
            <div className="text-[10px] text-slate-400 font-sans">
                IN: <span className="font-mono font-bold text-slate-300">{schedule.checkIn}</span>
            </div>
            <div className="text-[10px] text-slate-400 font-sans mt-0.5">
                OUT: <span className="font-mono font-bold text-slate-300">{schedule.checkOut}</span>
            </div>
            <div className="text-[10px] font-bold text-brand-400 mt-0.5 capitalize">
                {schedule.duration}
            </div>
        </div>
    );
}
