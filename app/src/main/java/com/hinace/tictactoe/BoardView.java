package com.hinace.tictactoe;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.view.MotionEvent;
import android.view.View;

public class BoardView extends View {

    public interface OnCellClickListener {
        void onCellClick(int row, int col);
    }

    private final Paint gridPaint = new Paint();
    private final Paint xPaint = new Paint();
    private final Paint oPaint = new Paint();
    private char[][] cells = new char[3][3];
    private OnCellClickListener listener;

    public BoardView(Context context) {
        super(context);
        gridPaint.setColor(Color.DKGRAY);
        gridPaint.setStrokeWidth(8f);
        gridPaint.setAntiAlias(true);

        xPaint.setColor(Color.parseColor("#E53935"));
        xPaint.setStrokeWidth(18f);
        xPaint.setAntiAlias(true);
        xPaint.setStrokeCap(Paint.Cap.ROUND);

        oPaint.setColor(Color.parseColor("#1E88E5"));
        oPaint.setStrokeWidth(18f);
        oPaint.setAntiAlias(true);
        oPaint.setStyle(Paint.Style.STROKE);
    }

    public void setOnCellClickListener(OnCellClickListener l) {
        this.listener = l;
    }

    public void setCellValue(int row, int col, char value) {
        cells[row][col] = value;
        invalidate();
    }

    public void clearBoard() {
        cells = new char[3][3];
        invalidate();
    }

    @Override
    protected void onMeasure(int widthMeasureSpec, int heightMeasureSpec) {
        int width = MeasureSpec.getSize(widthMeasureSpec);
        setMeasuredDimension(width, width);
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        int size = getWidth();
        float cellSize = size / 3f;

        for (int i = 1; i < 3; i++) {
            canvas.drawLine(cellSize * i, 0, cellSize * i, size, gridPaint);
            canvas.drawLine(0, cellSize * i, size, cellSize * i, gridPaint);
        }

        float pad = cellSize * 0.2f;
        for (int r = 0; r < 3; r++) {
            for (int c = 0; c < 3; c++) {
                float left = c * cellSize;
                float top = r * cellSize;
                char v = cells[r][c];
                if (v == 'X') {
                    canvas.drawLine(left + pad, top + pad, left + cellSize - pad, top + cellSize - pad, xPaint);
                    canvas.drawLine(left + cellSize - pad, top + pad, left + pad, top + cellSize - pad, xPaint);
                } else if (v == 'O') {
                    canvas.drawCircle(left + cellSize / 2f, top + cellSize / 2f, cellSize / 2f - pad, oPaint);
                }
            }
        }
    }

    @Override
    public boolean onTouchEvent(MotionEvent event) {
        if (event.getAction() == MotionEvent.ACTION_UP && listener != null) {
            float cellSize = getWidth() / 3f;
            int col = (int) (event.getX() / cellSize);
            int row = (int) (event.getY() / cellSize);
            if (row >= 0 && row < 3 && col >= 0 && col < 3) {
                listener.onCellClick(row, col);
            }
        }
        return true;
    }
}
