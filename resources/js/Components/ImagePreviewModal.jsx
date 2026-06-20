import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { X, ZoomIn } from 'lucide-react';

export default function ImagePreviewModal({ isOpen, imageUrl, onClose, altText = "Image Preview" }) {
    if (!isOpen || !imageUrl) return null;

    return (
        <AnimatePresence>
            {isOpen && (
                <>
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={onClose}
                        className="fixed inset-0 bg-[#070b13]/90 z-[9999]"
                    />
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95, y: 16 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.95, y: 16 }}
                        transition={{ type: 'spring', damping: 26, stiffness: 320 }}
                        className="fixed inset-0 z-[10000] flex flex-col items-center justify-center p-4 md:p-12 pointer-events-none"
                    >
                        <div className="relative max-w-4xl max-h-full flex flex-col pointer-events-auto">
                            <div className="flex justify-end mb-4">
                                <button
                                    onClick={onClose}
                                    className="p-2 rounded-xl bg-[#1e293b] border border-[#334155] text-slate-300 hover:text-white hover:bg-red-500/20 hover:border-red-500/50 transition-all shadow-xl"
                                >
                                    <X size={20} />
                                </button>
                            </div>
                            <div className="bg-[#1e293b] p-2 rounded-2xl shadow-2xl border border-[#334155] overflow-hidden flex-1 min-h-0 flex items-center justify-center">
                                <img 
                                    src={imageUrl} 
                                    alt={altText}
                                    className="max-w-full max-h-[75vh] object-contain rounded-xl"
                                />
                            </div>
                            <div className="mt-4 text-center text-slate-400 text-xs font-semibold uppercase tracking-widest flex items-center justify-center gap-2">
                                <ZoomIn size={14} /> {altText}
                            </div>
                        </div>
                    </motion.div>
                </>
            )}
        </AnimatePresence>
    );
}
