export interface BuuUmsSPersonRequest {
  facid: string;
  depid: string;
  fname: string;
  lname: string;
}

export interface BuuUmsSPersonPosition {
  adm_name_fac_name: string;
  hadm_status_name: string;
  hadm_startdate: string;
  hadm_enddate: string;
}

export interface BuuUmsSPersonResult {
  psn_id: string;
  psn_idcard: string;
  prf_nameth: string;
  prf_nameen: string;
  psn_technique: string | null;
  psn_fnameth: string;
  psn_lnameth: string;
  psn_fnameen: string;
  psn_lnameen: string;
  psn_gender: string;
  psn_startdate: string;
  liw_id: string;
  liw_nameth: string;
  cap_id: string;
  cap_nameth: string;
  fac_id: string;
  fac_nameth: string;
  dep_id: string;
  dep_nameth: string;
  maj_id: string | null;
  maj_nameth: string | null;
  hire_id: string;
  hire_nameth: string;
  grl_id: string;
  grl_nameth: string;
  psn_birthdate: string;
  uslogin: string;
  positions: BuuUmsSPersonPosition[];
}

export interface BuuUmsSPersonResponse {
  status: string;
  result: BuuUmsSPersonResult;
}
