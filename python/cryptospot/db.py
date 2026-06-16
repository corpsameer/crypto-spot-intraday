import mysql.connector

from cryptospot import config


def get_connection():
    return mysql.connector.connect(
        host=config.DB_HOST,
        port=config.DB_PORT,
        database=config.DB_DATABASE,
        user=config.DB_USERNAME,
        password=config.DB_PASSWORD,
    )


def fetch_one(query: str, params: tuple = None):
    connection = get_connection()
    cursor = None
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(query, params or ())
        return cursor.fetchone()
    finally:
        if cursor:
            cursor.close()
        connection.close()


def fetch_all(query: str, params: tuple = None):
    connection = get_connection()
    cursor = None
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(query, params or ())
        return cursor.fetchall()
    finally:
        if cursor:
            cursor.close()
        connection.close()


def execute(query: str, params: tuple = None):
    connection = get_connection()
    cursor = None
    try:
        cursor = connection.cursor()
        cursor.execute(query, params or ())
        connection.commit()
        return cursor.rowcount
    finally:
        if cursor:
            cursor.close()
        connection.close()


def execute_many(query: str, params: list):
    connection = get_connection()
    cursor = None
    try:
        cursor = connection.cursor()
        cursor.executemany(query, params or [])
        connection.commit()
        return cursor.rowcount
    finally:
        if cursor:
            cursor.close()
        connection.close()
