package com.hinace.tictactoe;

import android.app.Activity;
import android.graphics.Color;
import android.os.Bundle;
import android.view.Gravity;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.view.View;

public class MainActivity extends Activity {

    private char[][] board = new char[3][3];
    private boolean xTurn = true;
    private boolean gameOver = false;
    private TextView statusView;
    private BoardView boardView;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.WHITE);
        root.setLayoutParams(new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        statusView = new TextView(this);
        statusView.setText("Player X's turn");
        statusView.setTextSize(22f);
        statusView.setGravity(Gravity.CENTER);
        statusView.setPadding(0, 60, 0, 40);
        root.addView(statusView, new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));

        boardView = new BoardView(this);
        LinearLayout.LayoutParams boardParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        boardParams.setMargins(40, 0, 40, 40);
        boardView.setOnCellClickListener(new BoardView.OnCellClickListener() {
            @Override
            public void onCellClick(int row, int col) {
                handleMove(row, col);
            }
        });
        root.addView(boardView, boardParams);

        Button restartButton = new Button(this);
        restartButton.setText("New Game");
        restartButton.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                resetGame();
            }
        });
        LinearLayout.LayoutParams buttonParams = new LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        buttonParams.gravity = Gravity.CENTER_HORIZONTAL;
        root.addView(restartButton, buttonParams);

        setContentView(root);
    }

    private void handleMove(int row, int col) {
        if (gameOver || board[row][col] != 0) {
            return;
        }
        char mark = xTurn ? 'X' : 'O';
        board[row][col] = mark;
        boardView.setCellValue(row, col, mark);

        char winner = checkWinner();
        if (winner != 0) {
            statusView.setText("Player " + winner + " wins!");
            gameOver = true;
            return;
        }
        if (isBoardFull()) {
            statusView.setText("It's a draw!");
            gameOver = true;
            return;
        }

        xTurn = !xTurn;
        statusView.setText("Player " + (xTurn ? "X" : "O") + "'s turn");
    }

    private char checkWinner() {
        for (int i = 0; i < 3; i++) {
            if (board[i][0] != 0 && board[i][0] == board[i][1] && board[i][1] == board[i][2]) {
                return board[i][0];
            }
            if (board[0][i] != 0 && board[0][i] == board[1][i] && board[1][i] == board[2][i]) {
                return board[0][i];
            }
        }
        if (board[0][0] != 0 && board[0][0] == board[1][1] && board[1][1] == board[2][2]) {
            return board[0][0];
        }
        if (board[0][2] != 0 && board[0][2] == board[1][1] && board[1][1] == board[2][0]) {
            return board[0][2];
        }
        return 0;
    }

    private boolean isBoardFull() {
        for (int r = 0; r < 3; r++) {
            for (int c = 0; c < 3; c++) {
                if (board[r][c] == 0) {
                    return false;
                }
            }
        }
        return true;
    }

    private void resetGame() {
        board = new char[3][3];
        xTurn = true;
        gameOver = false;
        boardView.clearBoard();
        statusView.setText("Player X's turn");
    }
}
