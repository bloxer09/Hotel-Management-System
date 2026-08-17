import { useEffect } from 'react';
import CustomSelect from '@/Components/CustomSelect';
import { formatExpectedCheckout, minimumOvernightNights, overnightNightOptions, overnightStayHint } from '@/Utils/stayDuration';

export default function StayTypeFields({
    checkIn,
    bookingType,
    numNights,
    shortTimeHours,
    expectedCheckOut,
    onBookingTypeChange,
    onNightsChange,
    onHoursChange,
    inputCls,
    elevateWhenOpen = true,
}) {
    const minNights = minimumOvernightNights(checkIn);

    useEffect(() => {
        if (bookingType !== 'overnight') return;
        if (Number(numNights) >= minNights) return;

        onNightsChange({ target: { value: String(minNights) } });
    }, [bookingType, checkIn, minNights, numNights, onNightsChange]);

    return (
        <>
            <div className="flex flex-col gap-1">
                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Stay Type</label>
                <CustomSelect value={bookingType} onChange={onBookingTypeChange} className={`${inputCls} font-bold`} elevateWhenOpen={elevateWhenOpen}>
                    <option value="overnight">Overnight</option>
                    <option value="short_time">Short-time (Hourly)</option>
                </CustomSelect>
            </div>
            {bookingType === 'overnight' ? (
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Nights</label>
                    <CustomSelect value={String(Math.max(minNights, Number(numNights) || minNights))} onChange={onNightsChange} className={`${inputCls} font-mono font-bold`} elevateWhenOpen={elevateWhenOpen}>
                        {overnightNightOptions(checkIn, numNights).map((nights) => (
                            <option key={nights} value={nights}>
                                {nights === 1 ? '1 Night' : `${nights} Nights`}
                            </option>
                        ))}
                    </CustomSelect>
                    <p className="text-[9px] text-slate-500 leading-snug">{overnightStayHint(checkIn)}</p>
                </div>
            ) : (
                <div className="flex flex-col gap-1">
                    <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Hours</label>
                    <CustomSelect value={shortTimeHours} onChange={onHoursChange} className={`${inputCls} font-mono font-bold`} elevateWhenOpen={elevateWhenOpen}>
                        <option value={3}>3 Hours</option>
                        <option value={6}>6 Hours</option>
                        <option value={12}>12 Hours</option>
                        <option value={24}>24 Hours</option>
                    </CustomSelect>
                </div>
            )}
            <div className="flex flex-col gap-1 sm:col-span-2">
                <label className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Expected Check-Out</label>
                <div className={`${inputCls} font-mono font-bold text-slate-300 bg-[#0f172a]/70`}>
                    {expectedCheckOut ? formatExpectedCheckout(expectedCheckOut) : '—'}
                </div>
            </div>
        </>
    );
}
