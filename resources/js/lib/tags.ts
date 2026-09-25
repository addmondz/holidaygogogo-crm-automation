/**
 * Full class names (not built dynamically) so Tailwind includes them.
 */
export const tagColorClasses: Record<string, string> = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700',
    red: 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-950 dark:text-red-300 dark:ring-red-900',
    orange: 'bg-orange-100 text-orange-700 ring-orange-200 dark:bg-orange-950 dark:text-orange-300 dark:ring-orange-900',
    amber: 'bg-amber-100 text-amber-800 ring-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:ring-amber-900',
    green: 'bg-green-100 text-green-700 ring-green-200 dark:bg-green-950 dark:text-green-300 dark:ring-green-900',
    teal: 'bg-teal-100 text-teal-700 ring-teal-200 dark:bg-teal-950 dark:text-teal-300 dark:ring-teal-900',
    blue: 'bg-blue-100 text-blue-700 ring-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:ring-blue-900',
    indigo: 'bg-indigo-100 text-indigo-700 ring-indigo-200 dark:bg-indigo-950 dark:text-indigo-300 dark:ring-indigo-900',
    purple: 'bg-purple-100 text-purple-700 ring-purple-200 dark:bg-purple-950 dark:text-purple-300 dark:ring-purple-900',
    pink: 'bg-pink-100 text-pink-700 ring-pink-200 dark:bg-pink-950 dark:text-pink-300 dark:ring-pink-900',
};

export const tagDotClasses: Record<string, string> = {
    slate: 'bg-slate-500',
    red: 'bg-red-500',
    orange: 'bg-orange-500',
    amber: 'bg-amber-500',
    green: 'bg-green-500',
    teal: 'bg-teal-500',
    blue: 'bg-blue-500',
    indigo: 'bg-indigo-500',
    purple: 'bg-purple-500',
    pink: 'bg-pink-500',
};

export const statusClasses: Record<string, string> = {
    new: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    contacted:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    interested:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    booked: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    lost: 'bg-neutral-200 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};
