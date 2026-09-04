import type { ESignPerson } from '../types/esign';

export const PRESIDENT_PERSON: ESignPerson = {
  id: 'emp-president',
  name: 'ศ.ดร.สมพร ประธาน',
  position: 'อธิการบดี',
  department: 'อธิการบดีมหาวิทยาลัย • สำนักงานอธิการบดี',
  employeeId: 'EMP-2021-004',
  citizenId: '',
};

export const COUNCIL_CHAIR_PERSON: ESignPerson = {
  id: 'emp-council',
  name: 'ศ.ดร.วิชัย สภาธรรม',
  position: 'นายกสภามหาวิทยาลัย',
  department: 'สภามหาวิทยาลัย • สำนักงานสภา',
  employeeId: 'EMP-2018-001',
  citizenId: '',
};

export const DELEGATE_CANDIDATES: ESignPerson[] = [
  {
    id: 'emp-delegate-1',
    name: 'ศ.ดร. นงลักษณ์ ใจดี',
    position: 'คณบดีคณะวิทยาศาสตร์',
    department: 'คณะวิทยาศาสตร์',
    employeeId: 'EMP-2020-045',
  },
  {
    id: 'emp-delegate-2',
    name: 'รศ.ดร.สมชาย วิชาการ',
    position: 'รองอธิการบดีฝ่ายบริหาร',
    department: 'สำนักงานอธิการบดี',
    employeeId: 'EMP-2019-012',
  },
  {
    id: 'emp-delegate-3',
    name: 'ผศ.ดร.พิมพ์ใจ กฎหมาย',
    position: 'ผู้อำนวยการกองกฎหมาย',
    department: 'กองกฎหมาย',
    employeeId: 'EMP-2022-088',
  },
];
