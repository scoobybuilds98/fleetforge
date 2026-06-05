/**
 * FleetForge — Minimal inline signature pad (S-CCA-2).
 *
 * No external dependencies. Attach to a <canvas> element.
 * Usage:
 *   const pad = new SignaturePad(canvasEl);
 *   pad.clear();
 *   const png = pad.toPng();   // base64 data URI or '' if blank
 *   pad.isEmpty();             // true if never drawn on
 */
(function (global) {
    'use strict';

    function SignaturePad(canvas) {
        this._canvas   = canvas;
        this._ctx      = canvas.getContext('2d');
        this._drawing  = false;
        this._hasData  = false;

        this._ctx.strokeStyle = '#111827';
        this._ctx.lineWidth   = 2.2;
        this._ctx.lineCap     = 'round';
        this._ctx.lineJoin    = 'round';

        this._onDown   = this._onDown.bind(this);
        this._onMove   = this._onMove.bind(this);
        this._onUp     = this._onUp.bind(this);

        canvas.addEventListener('mousedown',  this._onDown);
        canvas.addEventListener('mousemove',  this._onMove);
        canvas.addEventListener('mouseup',    this._onUp);
        canvas.addEventListener('mouseleave', this._onUp);

        canvas.addEventListener('touchstart', this._onDown,  { passive: false });
        canvas.addEventListener('touchmove',  this._onMove,  { passive: false });
        canvas.addEventListener('touchend',   this._onUp);
    }

    SignaturePad.prototype._point = function (e) {
        var r = this._canvas.getBoundingClientRect();
        var src = e.touches ? e.touches[0] : e;
        return { x: src.clientX - r.left, y: src.clientY - r.top };
    };

    SignaturePad.prototype._onDown = function (e) {
        e.preventDefault();
        this._drawing = true;
        this._hasData = true;
        var p = this._point(e);
        this._ctx.beginPath();
        this._ctx.moveTo(p.x, p.y);
    };

    SignaturePad.prototype._onMove = function (e) {
        if (!this._drawing) return;
        e.preventDefault();
        var p = this._point(e);
        this._ctx.lineTo(p.x, p.y);
        this._ctx.stroke();
    };

    SignaturePad.prototype._onUp = function () {
        this._drawing = false;
    };

    SignaturePad.prototype.clear = function () {
        this._ctx.clearRect(0, 0, this._canvas.width, this._canvas.height);
        this._hasData = false;
    };

    SignaturePad.prototype.isEmpty = function () {
        return !this._hasData;
    };

    /** Returns base64 PNG data URI, or empty string if nothing drawn. */
    SignaturePad.prototype.toPng = function () {
        if (!this._hasData) return '';
        return this._canvas.toDataURL('image/png');
    };

    global.SignaturePad = SignaturePad;
}(window));
